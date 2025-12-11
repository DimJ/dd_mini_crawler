<?php

    require 'vendor/simplehtmldom/simplehtmldom/simple_html_dom.php';

    $crawler = new MiniCrawler('products.txt');
    $crawler->start_process();
    $crawler->print_db_info();

    class MiniCrawler {

        var $filename = '';
        var $db = null;

        function __construct( $inputFileName ){

            $this->filename = $inputFileName;

            unlink('log.txt');
            file_put_contents('log.txt', "\n");

            if (!$this->command_exist('curl')) {
                $this->save_to_log_file( 'ERROR', 'LOG', date('Y-m-d h:i:s'), 'SQL Could not create table', 'CURL command does not exist.', '' );
                exit('CURL command does not exist.');
            }

            try {
                $this->db = new PDO('sqlite:products.sqlite');
                $sql = "CREATE TABLE SITE_INFO (ID INT PRIMARY KEY NOT NULL, TITLE TEXT NOT NULL, ATTRIBUTES TEXT NOT NULL, DESCR TEXT NOT NULL); ";
                $this->db->exec($sql);
                $this->save_to_log_file( 'INFO', 'LOG', date('Y-m-d h:i:s'), 'SQL Table is created', 'We can proceed with the parsing process.', '' );
            } catch(PDOException $e) {
                $this->save_to_log_file( 'ERROR', 'LOG', date('Y-m-d h:i:s'), 'SQL Could not create table', $e->getMessage(), '' );
                exit('SQLite: '.$e->getMessage());
            }

        }

        public function start_process(){

            if($this->filename==''){
                $this->save_to_log_file( 'ERROR', 'LOG', date('Y-m-d h:i:s'), 'Empty Filename', '', '' );
                exit("Empty filename!\n");
            }

            if(!file_exists($this->filename)){
                $this->save_to_log_file( 'ERROR', 'LOG', date('Y-m-d h:i:s'), 'File does not exist', '', '' );
                exit("File does not exist!\n");
            }

            echo "Crawling process starts!\n";
            $this->save_to_log_file( 'INFO', 'LOG', date('Y-m-d h:i:s'), 'Crawling process starts', '', '' );

            $file_urls = $this->get_file_urls();

            if(count($file_urls)==0){
                $this->save_to_log_file( 'INFO', 'LOG', date('Y-m-d h:i:s'), 'File is empty', '', '' );
                exit("The file is empty!\n");
            }

            $counter = 1;
            foreach($file_urls as $url){
                $this->save_to_log_file( 'INFO', 'LOG', date('Y-m-d h:i:s'), 'Parsing starts', '', $url );
                $this->process_url($url, $counter);
                $this->save_to_log_file( 'INFO', 'LOG', date('Y-m-d h:i:s'), 'Parsing is over', '', $url );
                $counter++;
            }

            echo "Crawling process is over!\n";
            $this->save_to_log_file( 'INFO', 'LOG', date('Y-m-d h:i:s'), 'Crawling process is over', '', '' );

        }

        private function get_file_urls(){

            $urls = array(
                "https://christopherfarrcloth.com/drizzle-indoor-woven/",
                "https://jiunho.com/product/product_view/0/3337/light/0",
                "https://jiunho.com/product/product_view/0/1786/furniture/0",
                "https://jiunho.com/product/product_view/error",
                "http://nosite.com",
                "nothing"
            );

            // $handle = fopen($this->filename, "r");
            // if ($handle) {
            //     while (($line = fgets($handle)) !== false) {
            //         $urls[] = $line;
            //     }
            //     fclose($handle);
            // }

            return $urls;
        }

        private function process_url($url, $counter){

            echo $url."\n";
            $output=null;
            $retval=null;
            exec("curl $url", $output, $retval);

            if(count($output)==0){
                exec("curl $url", $output, $retval);
                $this->save_to_log_file( 'INFO', 'FETCH', date('Y-m-d h:i:s'), 'Retry procedure', '', $url );
            }

            if(count($output)>0){

                $this->save_to_log_file( 'INFO', 'FETCH', date('Y-m-d h:i:s'), 'Fetched url with code '.$counter, '', $url );
                $html = implode('', $output);
                file_put_contents('test.html', $html."\n");

                $html_dom = file_get_html('test.html');
                $this->save_to_log_file( 'INFO', 'PARSE', date('Y-m-d h:i:s'), 'Parsed url with code '.$counter, '', $url );

                $title = $this->get_title($html_dom);
                $attributes = $this->get_attributes($html_dom);
                $description = $this->get_description($html_dom);

                if($title != '' && $title != 'No title'){

                    try {
                        $insert = "INSERT INTO SITE_INFO (ID, TITLE, ATTRIBUTES, DESCR) VALUES (:id, :title, :attributes, :descr)";
                        $stmt = $this->db->prepare($insert);

                        $stmt->bindParam(':id', $counter);
                        $stmt->bindParam(':title', $title);
                        $stmt->bindParam(':attributes', $attributes);
                        $stmt->bindParam(':descr', $description);

                        $stmt->execute();
                        $this->save_to_log_file( 'INFO', 'SAVE', date('Y-m-d h:i:s'), 'Saved entry to sqlite', '', $url );

                    } catch(PDOException $e) {
                        $this->save_to_log_file( 'ERROR', 'LOG', date('Y-m-d h:i:s'), 'Problem while saving to sqlite.', '', $url );
                        exit($e->getMessage());
                    }
                }

                unlink('test.html');
            } else {
                $this->save_to_log_file( 'ERROR', 'LOG', date('Y-m-d h:i:s'), 'Not valid url', '', $url );
            }
        }

        private function get_title( $dom_object ){

            $title = '';

            $title_dom = $dom_object->find('title');

            if(isset($title_dom[0])){
                $title = $title_dom[0]->plaintext;
                $this->save_to_log_file( 'INFO', 'EXTRACT', date('Y-m-d h:i:s'), 'Extracted title', $title, '' );
            } else {
                $title = 'No title';
                $this->save_to_log_file( 'INFO', 'EXTRACT', date('Y-m-d h:i:s'), 'Could not extract title', '', '' );
            }

            return $title;
        }

        private function get_attributes( $dom_object ){

            $attributes = '';

            $attributes_dom = $dom_object->find('span.productView-specifications__value');

            foreach($attributes_dom as $attribute){
                $attributes .= ($attribute->plaintext."\n");
            }

            if($attributes != ''){
                $this->save_to_log_file( 'INFO', 'EXTRACT', date('Y-m-d h:i:s'), 'Extracted attributes', $attributes, '' );
            }

            return $attributes;
        }

        private function get_description( $dom_object ){

            $description = '';

            $description_dom = $dom_object->find('div.product-description');

            foreach($description_dom as $attribute){
                $description .= ($attribute->plaintext."\n");
            }

            if($description != ''){
                $this->save_to_log_file( 'INFO', 'EXTRACT', date('Y-m-d h:i:s'), 'Extracted description', $description, '' );
            }

            return $description;
        }

        public function print_db_info(){

            try {
                $sql = "SELECT * from SITE_INFO;";

                $result = $this->db->query($sql);

                foreach($result as $row) {
                    echo "Id: " . $row['ID'] . "\n";
                    echo "Title: " . $row['TITLE'] . "\n";
                    echo "Attributes: " . $row['ATTRIBUTES'] . "\n";
                    echo "Description: " . $row['DESCR'] . "\n";
                    echo "\n";
                }

                $this->db->exec("DROP TABLE SITE_INFO");
            } catch(PDOException $e) {
                exit($e->getMessage());
            }

        }

        private function save_to_log_file( $log_type, $type, $dateTime, $subject, $message, $related_url ){

            $info = array( 'log_type' => $log_type, 'type' => $type, 'date_time' => $dateTime, 'subject' => $subject, 'message' => $message, 'related_url' => $related_url );
            $appendVar = fopen('log.txt','a');
            fwrite($appendVar, implode(' | ', $info)."\n");
            fclose($appendVar);
        }

        private function command_exist($cmd) {
            $return = shell_exec(sprintf("which %s", escapeshellarg($cmd)));
            return !empty($return);
        }

    }

?>