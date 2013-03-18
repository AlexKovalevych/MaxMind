<?php

namespace MaxMind;

/*******************************************************************************

The GeoHandler packages up a bunch of processing and lookup functions, all in
one place, all called statically.

CorrelateCoordSrcIdName juggles an array of Human-readable names that represent
the accuracy of the lat/long data -- the lower the Id, the more accurate.
GetCoordSrcId and GetCoordSrcName are the public interface to look these up.

ManageCurrentLocations checks whether and how a lat/long lookup should be performed,
and in cases of members it tries to store the most accurate lat/long available,
while for visitors it stores lat/long in an Memcache key 'VisitorLocationLog' or
a corresponding file in the logs directory.

AddTo/RemoveFromArrayVisitorLocations coordinates the Set/Get to maintain the
structure of the overall array.

Set/GetArrayVisitorLocations records/returns the current VisitorLocationLog, an
associative array sorted by most recent pageview first, with IPs as keys in
this structure:
{IP address} => array(
'Latitude' => {visitor latitude},
'Longitude' => {visitor longitude},
'CountryCode' => {visitor CountryCode}
)
The VisitorLocationLog never expires, but maxes at $intMaxVisitorCount elements.

testcode__FillArrayVisitorLocations fills the VisitorLocationLog with random IPs,
for testing when on a dev environment without any visitors.

GetLocationData (formerly in the QApplication scope, and frequently requested there)
uses Google's geocoding API to lookup lat/long from address data; if it won't
be able to or if nothing is returned, it resorts to GeoIP lookup. The accuracy
index is returned as the 'src' value of the array.

GetGeoIp translates a MaxMind GeoIP lookup into our location data format.

OpenGeoIp is a port of the supplied geoip_open function in geoip.inc; the GeoIP
object, including the 28Mb memory buffer of the GeoLiteCity.dat file, is
stored in Memcache key 'GeoHandler::OpenGeoIp'
NOTE: Caching this data requires 30Mb of Memcache space; caching this file is
turned off with $blnCacheGeoIPDataFile

*******************************************************************************/


class GeoHandler {
    public static $strGoogleUrl = 'http://maps.google.com/maps/geo';
    public static $intMaxVisitorCount = 1000; // set to 0 to not log visitors (set blnStoreVisitorsInRAM to true to avoid file logging)
    private static $blnStoreVisitorsInRAM = USE_MEMCACHE; // true == uses memcache; false == uses file_put_contents/get_contents
    private static $strStoreVisitorsInFile = 'visitor_location.log'; // file name for Memcache fallback
    private static $arrAllVisitors = false; // NOTE: these are static functions, but this keeps cache calls to a minimum
    const GEO_CODE_LIMIT_REACHED = 620;

    protected static function CorrelateCoordSrcIdName($mixKnown) {
        // these potential sources of Latitude and Longitude are
        // listed in order of increased accuracy (note: NOT precision!)
        $arrSourceValues = array('Axiomatic', // pre-defined absolute values, like Greenwich, or the Equator; you'll never use this really
                'DirectEntry', // entered from GPS readout or similar
                'PostalAddress', // lookup for street address
                'UserGuess', // e.g., ask the user to click on a map
                'PostalCode', // just the postal code, but no street address; usually the geographic center
                'City', // just the city within a country; NOTE: this *can* be more accurate than some postal codes that include multiple cities, but most members statistically will be in large cities with many codes
                'IPGuess', // lookup based on IP address, which is somewhat dependant on the ISP service area and country regulations
                'Country', // often the capital city of the country; could be the geographic center
                'Continent', // no cities or countries in Antarctica, and why not be thorough?
                'Earth' // just a bookend; may need to be updated in 2025
                );

        if (is_int($mixKnown))
            return $arrSourceValues[$mixKnown];
        else
            return current(array_keys($arrSourceValues, $mixKnown));
    }

    public static function GetCoordSrcId($strName = '') {
        return self::CorrelateCoordSrcIdName($strName);
    }

    public static function GetCoordSrcName($intId = 0) {
        return self::CorrelateCoordSrcIdName($intId);
    }

    public static function GetSelfIp() {
        if (SERVER_INSTANCE == 'dev' && defined('GEOTEST') && GEOTEST == 1) { // set GEOTEST in local config if developing locally: grabs the external IP from web service
            return trim(file_get_contents('http://www.bivia.com/toolbox/iam.php'));
        } else {
            return QApplication::GetRemoteAddress();
        }
    }

    public static function ManageCurrentLocations($objUser = null) {
        if (is_object($objUser)) { // user is logged in...
            self::RemoveFromArrayVisitorLocations(self::GetSelfIP()); // if previously counted as a visitor, remove
            // if we have user-input location data, then the lat/long is already correct
            if (($objUser->Zipcode || $objUser->Location) && $objUser->Latitude && $objUser->Longitude) {
                // nothing
            } else { // any recorded lat/long is suspect and should be updated
                $objCountry = Country::Load($objUser->CountryId);
                $newLatLong = self::GetLocationData(array('street' => null, 'city' => $objUser->Location, 'state' => null, 'zipcode' => $objUser->Zipcode, 'country_code' => $objCountry->CountryCode, 'country_iso3' => $objCountry->Iso3, 'IP' => self::GetSelfIP()), true);
                if ($newLatLong) {
                    $objUser->Latitude = $newLatLong['Latitude'];
                    $objUser->Longitude = $newLatLong['Longitude'];
                    return true; // let the caller know that the data are freshly squeezed
                }
            }
        } else {
            if (self::LoggingVisitors() && !self::IsRobot() && !self::IsServer()) { // treat as visitor, but not robots
                if (!$_SESSION['location_managed']) { // hasn't been logged yet
                    $_SESSION['location_managed'] = true;
                    $arrVisitorLocation = self::GetLocationData(self::GetSelfIP());
                    if (!$arrVisitorLocation || !$arrVisitorLocation['Latitude']) {
                        return false;
                    }
                    self::AddToArrayVisitorLocations(self::GetSelfIP(), $arrVisitorLocation);
                }
            }
        }
    }

    public static function IsServer() {
        if (QApplication::GetRemoteAddress() == $_SERVER['SERVER_ADDR'] && SERVER_INSTANCE != 'dev') {
            return true;
        }
        return false;
    }

    public static function IsRobot() {
        $blnIsRobot = false;

        if (strpos($_SERVER['REQUEST_URI'], '/robots.') === 0) {
            $blnIsRobot = true;
        } else if (self::LoggingVisitors()) {
            ($arrRobotLog = MyMemcache::G()->GetQuery('RobotLog')) || ($arrRobotLog = array());
            if ($arrRobotLog && isset($arrRobotLog[self::GetSelfIp()])) {
                $blnIsRobot = true;
            }
        } else
            if (isset($_SERVER['HTTP_USER_AGENT']) && (preg_match("/(\b[\w-]+bot\b|crawl|spider|slurp|jeeves)/i", $_SERVER['HTTP_USER_AGENT']) || !preg_match("/(mozilla|msie|opera|gecko|webkit|khtml)/i", $_SERVER['HTTP_USER_AGENT']))) {
                $blnIsRobot = true;
            }

        if ($blnIsRobot && self::LoggingVisitors()) {
            $arrRobotLog || ($arrRobotLog = MyMemcache::G()->GetQuery('RobotLog')) || ($arrRobotLog = array());
            $strUA = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'no UA header provided';
            $strUA .= ' // last visit: ' . date('r');
            unset($arrRobotLog[QApplication::GetRemoteAddress()]);
            $arrRobotLog = array(QApplication::GetRemoteAddress() => $strUA) + $arrRobotLog;
            MyMemcache::G()->SetQuery('RobotLog', array_slice($arrRobotLog, 0, self::$intMaxVisitorCount), null, 3600);
        }

        return $blnIsRobot;
    }

    protected static function LoggingVisitors() {
        //return (self::$intMaxVisitorCount > 0 && defined('USE_MEMCACHE') && USE_MEMCACHE == 1);
        return (self::$intMaxVisitorCount > 0);
    }

    public static function AddToArrayVisitorLocations($strIP, $arrVisitorLocation) {
        $arrAllVisitors = self::GetArrayVisitorLocations();
        if (is_array($arrAllVisitors)) { // when starting from scratch, this is null
            if (isset($arrAllVisitors[$strIP])) {
                $arrVisitorLocation = $arrAllVisitors[$strIP];
            }
        } else {
            $arrAllVisitors = array();
        }
        return self::SetArrayVisitorLocations(array($strIP => $arrVisitorLocation) + $arrAllVisitors);
    }

    public static function RemoveFromArrayVisitorLocations($strIP) {
        $arrAllVisitors = self::GetArrayVisitorLocations();
        if (isset($arrAllVisitors[$strIP])) {
            unset($arrAllVisitors[$strIP]);
            return self::SetArrayVisitorLocations($arrAllVisitors);
        } else
            return true;
    }

    public static function SetArrayVisitorLocations($arrAllVisitors = array()) {
        if (is_array($arrAllVisitors)) {
            self::$arrAllVisitors = array_slice($arrAllVisitors, 0, self::$intMaxVisitorCount);
            if (self::$blnStoreVisitorsInRAM) {
                return self::LoggingVisitors() ? MyMemcache::G()->SetQuery('VisitorLocationLog', self::$arrAllVisitors, null, 3600) : false;
            } else {
                return (boolean)file_put_contents(LOG_PATH . '/' . self::$strStoreVisitorsInFile, serialize(self::$arrAllVisitors));
            }
        } else
            return false;
    }

    public static function GetArrayVisitorLocations($intLimit = 0) {
        if (!self::$arrAllVisitors && self::LoggingVisitors()) {
            if (self::$blnStoreVisitorsInRAM) {
                self::$arrAllVisitors = MyMemcache::G()->GetQuery('VisitorLocationLog');
            } else {
                self::$arrAllVisitors = unserialize(@file_get_contents(LOG_PATH . '/' . self::$strStoreVisitorsInFile));
                if (!is_array(self::$arrAllVisitors))
                    self::$arrAllVisitors = false;
            }
        }
        if ($intLimit && is_array(self::$arrAllVisitors))
            return array_slice(self::$arrAllVisitors, 0, $intLimit);
        else
            return self::$arrAllVisitors;
    }

    public static function GetLocationData($arrLocationData = false, $blnReturnSimple = true, $blnStoreSession = true, $strWebServiceUrl = null) {
        if ($blnStoreSession) {
            $strCacheKey = 'LocationData:' . serialize($arrLocationData);
            if ($_SESSION[$strCacheKey]) {
                return $_SESSION[$strCacheKey];
            }
        }

        if (!is_array($arrLocationData) || !(($arrLocationData['city'] && $arrLocationData['state']) || ($arrLocationData['city'] && $arrLocationData['country_iso3']) || $arrLocationData['zipcode'] || $arrLocationData['location'] || $arrLocationData['country'])) { // not enough text info; grab from IP address
            if (is_string($arrLocationData) && preg_match("/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/", $arrLocationData)) {
                $objResultData = self::GetGeoIp($arrLocationData);
            } else if (is_array($arrLocationData) && $arrLocationData['IP']) {
                $objResultData = self::GetGeoIp($arrLocationData['IP']);
            } else if ($arrLocationData = self::GetSelfIP()) {
                $objResultData = self::GetGeoIp($arrLocationData);
            } else {
                $objResultData = false;
                $strCacheKey = false; // don't cache it if it wasn't called with params
            }
        } else { // enough text info to geocode through Google
            $objResultData['src'] = self::GetCoordSrcId('Earth');
            if (!$strWebServiceUrl) {
                $strWebServiceUrl = self::$strGoogleUrl;
            }
            $strWebServiceUrl .= '?output=xml&key=' . GOOGLE_MAP_KEY . '&q=';

            if ($arrLocationData['location']) {
                $strWebServiceUrl .= urlencode($arrLocationData['location']);
                $objResultData['src'] = min($objResultData['src'], self::GetCoordSrcId('City'));
            } else {
                $strLocation = '';
                $strSimpleLocation = '';
                if ($arrLocationData['street']) {
                    $strLocation .= urlencode($arrLocationData['street']) . ',';
                    $objResultData['src'] = min($objResultData['src'], self::GetCoordSrcId('PostalAddress'));
                }
                if ($arrLocationData['city']) {
                    $strLocation .= urlencode($arrLocationData['city']) . ',';
                    $strSimpleLocation .= urlencode($arrLocationData['city']) . ',';
                    $objResultData['src'] = min($objResultData['src'], self::GetCoordSrcId('City'));
                }
                if ($arrLocationData['state']) {
                    $strLocation .= urlencode($arrLocationData['state']) . ',';
                    $objResultData['src'] = min($objResultData['src'], self::GetCoordSrcId('Country'));
                }
                if ($arrLocationData['zipcode']) {
                    $strLocation .= urlencode($arrLocationData['zipcode']) . ',';
                    $objResultData['src'] = min($objResultData['src'], self::GetCoordSrcId('PostalCode'));
                }
                /*if($arrLocationData['country']) {
                  $strLocation .= urlencode($arrLocationData['country']);
                  $strSimpleLocation .= urlencode($arrLocationData['country']);
                  $objResultData['src'] = min($objResultData['src'], self::GetCoordSrcId('Country'));
                  } elseif($arrLocationData['country_code']) {
                  $strLocation .= urlencode($arrLocationData['country_code']);
                  $strSimpleLocation .= urlencode($arrLocationData['country_code']);
                  $objResultData['CountryCode'] = $arrLocationData['country_code'];
                  $objResultData['src'] = min($objResultData['src'], self::GetCoordSrcId('Country'));
                  }*/

                $strWebServiceUrlSimple = $strWebServiceUrl . $strSimpleLocation;
                $strWebServiceUrl .= $strLocation;
            }

            QApplication::SetErrorHandler(null);
            //exit($strWebServiceUrl);
            $xml = @simplexml_load_file($strWebServiceUrl);
            QApplication::RestoreErrorHandler();
            //print_r($xml);
            //exit;
            //echo '<br><br>';

            unset($strCoordinates);
            $continue = true;
            $delay = 0;

            if ($xml) {
                $status = (string) $xml->Response->Status->code;

                if (strcmp($status, "200") == 0) {
                    // Successful geocode
                    $strCoordinates = (string) $xml->Response->Placemark->Point->coordinates;
                } else if (strcmp($status, "620") == 0) {
                    // sent geocodes too fast or > 15,000 requests in 24 hours per IP
                    $delay = 100000;
                    $continue = false;
                }
            }

            if (!$strCoordinates && $blnReturnSimple && $continue) {
                QApplication::SetErrorHandler(null);
                $xml = @simplexml_load_file($strWebServiceUrlSimple);
                QApplication::RestoreErrorHandler();

                if ($xml) {
                    $status = (string) $xml->Response->Status->code;
                    if (strcmp($status, "200") == 0) {
                        // Successful geocode
                        $strCoordinates = (string) $xml->Response->Placemark->Point->coordinates;
                    }
                } else {
                    if (strcmp($status, "620") == 0) {
                        // sent geocodes too fast or > 15,000 requests in 24 hours per IP
                        $delay = 100000;
                        $continue = false;
                    }
                }
            }

            if ($strCoordinates && $continue) {
                $coordinatesSplit = split(",", $strCoordinates);
                // Format: Longitude, Latitude, Altitude
                $latitude = $coordinatesSplit[1];
                $longitude = $coordinatesSplit[0];
                $strPostalCode = (string) $xml->Response->Placemark->AddressDetails->Country->AdministrativeArea->Locality->PostalCode->PostalCodeNumber;
                //echo "$latitude - $longitude - $strPostalCode<br><br>";

                $objResultData['Longitude'] = $longitude;
                $objResultData['Latitude'] = $latitude;
                $objResultData['Zipcode'] = $strPostalCode;
            } else {
                //$objResultData = self::GetGeoIp(self::GetSelfIP());
                usleep($delay);
                return self::GEO_CODE_LIMIT_REACHED;
            }
        }

        if ($strCacheKey) {
            $_SESSION[$strCacheKey] = $objResultData;
        }

        return $objResultData;
    }


    /*public static function GetGeoIpOld($strIp) {
      if ($strIp) {
      $gi = GeoHandler::OpenGeoIp();
      $record = geoip_record_by_addr($gi, $strIp);
      if ($record && $record->longitude && $record->latitude) {
    // NOTE: the system is expecting strings, not floats?
    $objResultData['Longitude'] = (string) $record->longitude;
    $objResultData['Latitude'] = (string) $record->latitude;
    $objResultData['CountryCode'] = $record->country_code;
    $objResultData['src'] = self::GetCoordSrcId('IPGuess');
    return $objResultData;
    }
    }
    return false;
    }

    protected static function OpenGeoIp($blnFresh = false) {
    require_once dirname(__FILE__) . '/GeoIP/geoipcity.inc';
    require_once dirname(__FILE__) . '/GeoIP/geoipregionvars.php';
    $gi = false;
    if ( ! $blnFresh && self::$blnCacheGeoIPDataFile && defined('USE_MEMCACHE') && USE_MEMCACHE == 1) { // then check the cache
    $gi = MyMemcache::G()->GetQuery('GeoHandler::OpenGeoIp');
    }
    if ( ! $gi) {
    $gi = new GeoIP;
    $gi->flags = GEOIP_MEMORY_CACHE;
    $gi->filehandle = fopen(SETTINGS_PATH . '/GeoLiteCity.dat',"rb");
    $s_array = fstat($gi->filehandle);
    $gi->memory_buffer = fread($gi->filehandle, $s_array['size']);
    $gi = _setup_segments($gi);

    if (self::$blnCacheGeoIPDataFile && defined('USE_MEMCACHE') && USE_MEMCACHE == 1) { // store in MEMCACHE for 2 hours
    MyMemcache::G()->SetQuery('GeoHandler::OpenGeoIp', $gi, null, (2 * 3600));
    }
    }
    return $gi;
    }*/

    public static function GetGeoIp($strIp) {
        if ($strIp && GEOIP_LICENSE_KEY && !self::IsRobot() && !self::IsServer()) {
            $arrAllVisitors = self::GetArrayVisitorLocations();
            if ($arrAllVisitors[$strIp]) {
                return $arrAllVisitors[$strIp];
            }

            file_put_contents(LOG_PATH . '/geo_ip_call.log', date('Y-m-d H:i:s') . ' ' . $strIp . ' : ', FILE_APPEND);

            require_once CUSTOM_CLASS_PATH . '/GeoCityLocateIspOrg.inc';
            $objGeoIp = GeoCityLocateIspOrg::getInstance($strIp);

            if ($objGeoIp->isError()) {
                file_put_contents(LOG_PATH . '/geo_ip_call.log', "ERROR\n", FILE_APPEND);
                return false;
            }

            $arrResultData['City'] = self::cleanNull($objGeoIp->getCity());
            $arrResultData['State'] = self::cleanNull($objGeoIp->getState());
            $arrResultData['Zip'] = self::cleanNull($objGeoIp->getZip());
            $arrResultData['CountryCode'] = self::cleanNull($objGeoIp->getCountryCode());
            $arrResultData['MetroCode'] = self::cleanNull($objGeoIp->getMetroCode());
            $arrResultData['AreaCode'] = self::cleanNull($objGeoIp->getAreaCode());
            $arrResultData['ISP'] = self::cleanNull($objGeoIp->getISP());
            $arrResultData['Organization'] = self::cleanNull($objGeoIp->getOrganization());
            $arrResultData['Latitude'] = self::cleanNull($objGeoIp->getLatitude());
            $arrResultData['Longitude'] = self::cleanNull($objGeoIp->getLongitude());

            file_put_contents(LOG_PATH . '/geo_ip_call.log', "{$arrResultData['City']}, {$arrResultData['State']}, {$arrResultData['Zip']}, {$arrResultData['CountryCode']}, {$arrResultData['Longitude']}, {$arrResultData['Latitude']}\n", FILE_APPEND);

            return $arrResultData;
        }
        return false;
    }

    private static function cleanNull($strValue) {
        if ($strValue == '(null)') {
            return '';
        }
        return $strValue;
    }
}

?>
