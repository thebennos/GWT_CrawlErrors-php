<?php
ini_set('max_execution_time', 600);
ini_set('memory_limit', '64M');

class GwtCrawlErrors
{
    const HOST = "https://www.google.com";
    const SERVICEURI = "/webmasters/tools/";

    public function __construct()
    {
        $this->_auth     = false;
        $this->_loggedIn = false;
        $this->_domain   = false;
        $this->_data     = array();
    }

    public function getArray($domain)
    {
        $this->_domain = $domain;
        return false !== $this->_prepareData() ? $this->_data() : false;
    }

    public function getCsv($domain, $localPath = false)
    {
        $this->_domain = $domain;
        if (false !== $this->_prepareData()) {
            if (!$localPath) {
                $this->_HttpHeaderCSV();
                $this->_outputCSV();
            }
            else {
                $this->_outputCSV($localPath);
            }
        }
        else {
            return false;
        }
    }

    public function getSites()
    {
        if(true === $this->_loggedIn) {
            $feed = $this->_getData('feeds/sites/');
            if($feed !== false) {
                $sites = array();

                $doc = new DOMDocument();
                $doc->loadXML($feed);

                foreach ($doc->getElementsByTagName('entry') as $node) {
                    array_push($sites,
                      $node->getElementsByTagName('title')->item(0)->nodeValue);
                }

                return $sites;
            }
            else {
                return false;
            }
        }
        else {
            return false;
        }
    }

    public function login($email, $pwd)
    {
        $postRequest = array(
            'accountType' => 'HOSTED_OR_GOOGLE',
            'Email'       => $email,
            'Passwd'      => $pwd,
            'service'     => "sitemaps",
            'source'      => "Google-WMTdownloadscript-0.11-php"
        );

        $ch = curl_init(self::HOST . '/accounts/ClientLogin');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => $postRequest
        ));

        $output = curl_exec($ch);
        $info   = curl_getinfo($ch);
        curl_close($ch);

        if (200 != $info['http_code']) {
            return false;
        }
        else {
            @preg_match('/Auth=(.*)/', $output, $match);
            if (isset($match[1])) {
                $this->_auth = $match[1];
                $this->_loggedIn = true;
                return true;
            }
            else {
                return false;
            }
        }
    }

    private function _prepareData()
    {
        if (true === $this->_loggedIn) {
            $currentIndex = 1;
            $maxResults   = 100;

            $encUri = urlencode($this->_domain);

            /*
             * Get the total result count / result page count
             */
            $feed = $this->_getData("feeds/{$encUri}/crawlissues?start-index=1&max-results=1");
            if (!$feed) {
                return false;
            }

            $doc = new DOMDocument();
            $doc->loadXML($feed);

            $totalResults = (int)$doc->getElementsByTagNameNS('http://a9.com/-/spec/opensearch/1.1/', 'totalResults')->item(0)->nodeValue;
            $resultPages  = (0 != $totalResults) ? ceil($totalResults / $maxResults) : false;

            unset($feed, $doc);

            if (!$resultPages) {
                return false;
            }

            /*
             * Paginate over issue feeds
             */
            else {
                // Csv data headline
                $this->_data = Array(
                    Array('Issue Id', 'Crawl type', 'Issue type', 'Detail', 'URL', 'Date detected', 'Updated')
                );

                while ($currentIndex <= $resultPages) {
                    $startIndex = ($maxResults * ($currentIndex - 1)) + 1;

                    $feed = $this->_getData("feeds/{$encUri}/crawlissues?start-index={$startIndex}&max-results={$maxResults}");
                    $doc  = new DOMDocument();
                    $doc->loadXML($feed);

                    foreach ($doc->getElementsByTagName('entry') as $node) {
                        $issueId = str_replace(
                            self::HOST . self::SERVICEURI . "feeds/{$encUri}/crawlissues/",
                            '',
                            $node->getElementsByTagName('id')->item(0)->nodeValue
                        );
                        $crawlType    = $node->getElementsByTagNameNS('http://schemas.google.com/webmasters/tools/2007', 'crawl-type')->item(0)->nodeValue;
                        $issueType    = $node->getElementsByTagNameNS('http://schemas.google.com/webmasters/tools/2007', 'issue-type')->item(0)->nodeValue;
                        $detail       = $node->getElementsByTagNameNS('http://schemas.google.com/webmasters/tools/2007', 'detail')->item(0)->nodeValue;
                        $url          = $node->getElementsByTagNameNS('http://schemas.google.com/webmasters/tools/2007', 'url')->item(0)->nodeValue;
                        $dateDetected = date('d/m/Y', strtotime($node->getElementsByTagNameNS('http://schemas.google.com/webmasters/tools/2007', 'date-detected')->item(0)->nodeValue));
                        $updated      = date('d/m/Y', strtotime($node->getElementsByTagName('updated')->item(0)->nodeValue));

                        // add issue data to results array
                        array_push($this->_data,
                            Array($issueId, $crawlType, $issueType, $detail, $url, $dateDetected, $updated)
                        );
                    }

                    unset($feed, $doc);
                    $currentIndex++;
                }
                return true;
            }
        }
        else {
            return false;
        }
    }

    private function _getData($url)
    {
        if (true === $this->_loggedIn) {
            $header = array(
                'Authorization: GoogleLogin auth=' . $this->_auth,
                'GData-Version: 2'
            );

            $ch = curl_init(self::HOST . self::SERVICEURI . $url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_ENCODING       => 1,
                CURLOPT_HTTPHEADER     => $header
            ));

            $result = curl_exec($ch);
            $info   = curl_getinfo($ch);
            curl_close($ch);

            return (200 != $info['http_code']) ? false : $result;
        }
        else {
            return false;
        }
    }

    private function _HttpHeaderCSV() {
        header('Content-type: text/csv; charset=utf-8');
        header('Content-disposition: attachment; filename=gwt-crawlerrors-' .
            $this->_getFilename());
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    private function _outputCSV($localPath = false) {
        $outstream = !$localPath ? 'php://output' : $localPath . DIRECTORY_SEPARATOR . $this->_getFilename();
        $outstream = fopen($outstream, "w");
        function __outputCSV(&$vals, $key, $filehandler) {
            fputcsv($filehandler, $vals); // add parameters if you want
        }
        array_walk($this->_data, "__outputCSV", $outstream);
        fclose($outstream);
    }

    private function _getFilename()
    {
        return 'gwt-crawlerrors-' .
            parse_url($this->_domain, PHP_URL_HOST) .'-'.
            date('Ymd-His') . '.csv';
    }
}