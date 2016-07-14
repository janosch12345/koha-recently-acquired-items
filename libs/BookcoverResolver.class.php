<?php

class BookcoverResolver {

    private $debug = false;
    private $order = array("amazon", "google");
    //API keys and stuff
    private $googleAPIKey = '';
    //amzon: https://portal.aws.amazon.com
    private $amazonPublicKey = ""; //accesskey
    private $amazonPrivateKey = ""; //secret accesskey
    private $amazonAssociateTag = ""; //
    public $streamingContext = null;

    //
    function __construct() {
        //f.e. proxy 
        $this->streamingContext = $this->getStreamingContext();
    }

    // Deklaration Methoden
    public function getStreamingContext() {
        $aContext = array(
            'http' => array(
                'proxy' => 'tcp://proxy',
                'request_fulluri' => True,
            ),
        );
        $cxContext = stream_context_create($aContext);
        return $cxContext;
    }

    /** 
     * This should be called
     * identifier is isbn/asin/ean number
     * if you know what type of identifier you have set it as 'type'
     * else set type = smart and the logic will determine the type 
     * @param type $identifier
     * @param type $type
     * @return string
     */
    public function getCover($identifier, $type = 'smart') {
        $identifier = str_replace(' ', '', $identifier);
        $identifier = str_replace("-", "", $identifier);


        if ("smart" == $type or "" == $type) {
            //isbn:
            $regex_isbn = '/^(97(8|9))?\d{9}(\d|X)$/';
            if (preg_match($regex_isbn, $identifier))
                $type = 'isbn';
            else {
                $regex_ean = '/^\d{13}$|^\d{8}$/';
                if (preg_match($regex_ean, $identifier))
                    $type = 'ean';
                else
                    $type = 'asin';
            }
        } else {
            $type = 'isbn';
        }

        $cover = false;
        foreach ($this->order as $provider) {
            //we use google only for isbn bookcovers
            if ($provider == 'google' and ! $cover and $type == 'isbn') {
                $cover = $this->getGoogleCover($identifier);
            }
            if ($provider == 'amazon' and ! $cover) {
                $cover = $this->getAmazonCover($identifier, $type);
            }
        }
        if (!$cover)
            $cover = 'blank.gif';

        return $cover;
    }

    public function getOpenLibraryCover($isbn) {
        
    }

    public function getGoogleCover($identifier) {
        $googleAPIKey = $this->googleAPIKey;
        $identifier = str_replace("-", "", $identifier);
        $url = 'https://www.googleapis.com/books/v1/volumes?q=' . $identifier . '&key=' . $googleAPIKey;
        //echo $url."\n";
        $mycov = array();
        $mycov['identifier'] = $identifier;

        $response = file_get_contents($url, False, $this->streamingContext);

        //could happen
        /*
          {
          "error": {
          "errors": [
          {
          "domain": "usageLimits",
          "reason": "dailyLimitExceeded",
          "message": "Daily Limit Exceeded"
          }
          ],
          "code": 403,
          "message": "Daily Limit Exceeded"
          }
          }
         */

        $obj = json_decode($response, true);
        //if (true) print_r($obj);
        $cover = false;
        if (@array_key_exists("imageLinks", $obj['items'][0]['volumeInfo'])) {
            $cover = $obj['items'][0]['volumeInfo']['imageLinks']['thumbnail'];
            //return $obj['items'][0]['volumeInfo']['imageLinks'];
        }

        if ($this->debug)
            echo $mycov['identifier'] . " -> " . $cover . "\n";
        return $cover;
    }

    public function getAmazonCover($identifier, $type) {

        $amazonPublicKey = $this->amazonPublicKey;
        $amazonPrivateKey = $this->amazonPrivateKey;
        $amazonAssociateTag = $this->amazonAssociateTag;
        $mycov = array();

        if ("smart" == $type or "" == $type) {
            //isbn:
            $regex_isbn = '/^(97(8|9))?\d{9}(\d|X)$/';
            if (preg_match($regex_isbn, $identifier))
                $type = 'isbn';
            else {
                $regex_ean = '/^\d{13}$|^\d{8}$/';
                if (preg_match($regex_ean, $identifier))
                    $type = 'ean';
                else
                    $type = 'asin';
            }
        } else {
            //$type ='asin';
        }

        $mycov['identifier'] = $identifier;
        $mycov['type'] = $type;

        $successful = false;

        while (!$successful) {
            if ($type == 'isbn') {
                $res = $this->aws_signed_request("de", array(
                    "AssociateTag" => $amazonAssociateTag,
                    "Service" => "AWSECommerceService",
                    "SearchIndex" => "Books",
                    "Operation" => "ItemLookup",
                    "ItemId" => $identifier,
                    "IdType" => "ISBN",
                    "ResponseGroup" => "Images",
                    "Version" => "2009-03-31"), $amazonPublicKey, $amazonPrivateKey);
            } else if ($type == 'ean') {
                $res = $this->aws_signed_request("de", array(
                    "AssociateTag" => $amazonAssociateTag,
                    "Service" => "AWSECommerceService",
                    "Operation" => "ItemLookup",
                    "SearchIndex" => "DVD",
                    "ItemId" => $identifier,
                    "IdType" => "EAN",
                    "ResponseGroup" => "Images",
                    "Version" => "2009-03-31"), $amazonPublicKey, $amazonPrivateKey);
            } else if ($type == 'asin') {
                $res = $this->aws_signed_request("de", array(
                    "AssociateTag" => $amazonAssociateTag,
                    "Service" => "AWSECommerceService",
                    "Operation" => "ItemLookup",
                    "ItemId" => $identifier,
                    "IdType" => "ASIN",
                    "ResponseGroup" => "Images",
                    "Version" => "2009-03-31"), $amazonPublicKey, $amazonPrivateKey);
            }

            $mycov['response'] = $res;
            if ($res['code'] == '200') {
                if ($res['response']) {
                    $pxml = $res['response'];
                    $successful = true;
                } else {
                    if ($this->debug)
                        echo "\n " . $mycov['identifier'] . " -> this should never happen\n";
                    exit;
                }
            } else if ($res['code'] == '503') {
                //try again
                usleep(1000000);
                
            } else if ($res['code'] == '403') {
                if ($this->debug)
                    echo "\n " . $mycov['identifier'] . " -> error 403 \n";
                exit;
            } else {
                if ($this->debug)
                    echo "\n " . $mycov['identifier'] . " -> unhandled response error code: " . $res['code'] . " \n";
                exit;
            }
        }//end while !successful

        if ($pxml === False) {
            return null;
        } else {
            $cover = false;
            //$covers = array();
            //if (isset($pxml->Items->Item->SmallImage))	{$covers['SmallImage'] = $pxml->Items->Item->SmallImage->URL."";}
            if (isset($pxml->Items->Item->MediumImage))
                $cover = $pxml->Items->Item->MediumImage->URL . "";
            //if (isset($pxml->Items->Item->LargeImage))	{$covers['LargeImage'] = $pxml->Items->Item->LargeImage->URL."";}
        }
        if ($this->debug)
            echo $mycov['identifier'] . " -> " . $cover . "\n";
        return $cover;
    }

    function aws_signed_request($region, $params, $public_key, $private_key) {
        /*
          Copyright (c) 2009 Ulrich Mierendorff

          Permission is hereby granted, free of charge, to any person obtaining a
          copy of this software and associated documentation files (the "Software"),
          to deal in the Software without restriction, including without limitation
          the rights to use, copy, modify, merge, publish, distribute, sublicense,
          and/or sell copies of the Software, and to permit persons to whom the
          Software is furnished to do so, subject to the following conditions:

          The above copyright notice and this permission notice shall be included in
          all copies or substantial portions of the Software.

          THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
          IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
          FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
          THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
          LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
          FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
          DEALINGS IN THE SOFTWARE.
         */

        /*
          Parameters:
          $region - the Amazon(r) region (ca,com,co.uk,de,fr,jp)
          $params - an array of parameters, eg. array("Operation"=>"ItemLookup",
          "ItemId"=>"B000X9FLKM", "ResponseGroup"=>"Small")
          $public_key - your "Access Key ID"
          $private_key - your "Secret Access Key"
         */

        // some paramters
        $method = "GET";
        $host = "ecs.amazonaws." . $region;
        $uri = "/onca/xml";

        // additional parameters
        $params["Service"] = "AWSECommerceService";
        $params["AWSAccessKeyId"] = $public_key;
        // GMT timestamp
        $params["Timestamp"] = gmdate("Y-m-d\TH:i:s\Z");
        // API version
        $params["Version"] = "2009-03-31";

        // sort the parameters
        ksort($params);

        // create the canonicalized query
        $canonicalized_query = array();
        foreach ($params as $param => $value) {
            $param = str_replace("%7E", "~", rawurlencode($param));
            $value = str_replace("%7E", "~", rawurlencode($value));
            $canonicalized_query[] = $param . "=" . $value;
        }
        $canonicalized_query = implode("&", $canonicalized_query);

        // create the string to sign
        $string_to_sign = $method . "\n" . $host . "\n" . $uri . "\n" . $canonicalized_query;

        // calculate HMAC with SHA256 and base64-encoding
        $signature = base64_encode(hash_hmac("sha256", $string_to_sign, $private_key, True));

        // encode the signature for the request
        $signature = str_replace("%7E", "~", rawurlencode($signature));

        // create request
        $request = "http://" . $host . $uri . "?" . $canonicalized_query . "&Signature=" . $signature;

        // do request
        //echo "\nRequest--------------\n".($request)."\n--------------\n";
        $resp = array();
        $response = @file_get_contents($request, False, $this->streamingContext);
        if (strpos($http_response_header[0], "200")) {
            $resp['code'] = 200;
        } else if (strpos($http_response_header[0], "503")) {
            $resp['code'] = 503;
            return $resp;
        } else if (strpos($http_response_header[0], "403")) {
            $resp['code'] = 403;
            return $resp;
        } else {
            //echo "HTTP response header: ".$http_response_header[0]."\n";
            $resp['code'] = $http_response_header[0];
            return $resp;
        }

        //echo "\nResponse--------------\n".$response."\n--------------\n";
        if ($response === False) {
            $resp['response'] = false;
            return $resp;
        } else {
            // parse XML
            $pxml = simplexml_load_string($response);
            if ($pxml === False) {
                $resp['response'] = false;
                return $resp; // no xml
            } else {
                $resp['response'] = $pxml;
                return $resp;
            }
        }
    }

//Ende aws_signed_request
}

?>
