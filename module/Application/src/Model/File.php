<?php

namespace Application\Model;

use RuntimeException;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Sql\Sql;

class File
{
    private $adapter;
    protected $file_table = 'file_import_contents';
    protected $appel_table = 'appel';

    CONST DATA = 'DATA';
    CONST CALL = 'APPEL';
    CONST SMS = 'SMS';
    CONST MAX_VALUE = 1000;

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @param string $file_path
     * @return array
     */
    public function loadDataFromCSV($file_path)
    {
        try {
            $this->flushTable($this->file_table);
            $file_path = $this->normalize($file_path);
            
            if($this->importFileContents($file_path))
            {
                $this->flushTable($this->appel_table);
                return $this->fillContent();
            }
        }
        catch(RuntimeException $e) {
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Convert file line endings to uniform "\r\n" to solve for EOL issues
     * Files that are created on different platforms use different EOL characters
     * This method will convert all line endings to Unix uniform
     *
     * @param string $file_path
     * @return string $file_path
     */
    protected function normalize($file_path)
    {
        //Load the file into a string
        $string = @file_get_contents($file_path);

        if (!$string) {
            return $file_path;
        }

        //Convert all line-endings using regular expression
        $string = preg_replace('~\r\n?~', "\n", $string);

        file_put_contents($file_path, $string);

        return $file_path;
    }

    /**
     * Import CSV file into Database using LOAD DATA LOCAL INFILE function
     *
     * @param $file_path
     * @return mixed Will return number of lines imported by the query
     */
    private function importFileContents($file_path)
    {
        $query = sprintf("LOAD DATA LOCAL INFILE '%s' 
            REPLACE INTO TABLE %s 
            CHARACTER SET latin1  
            FIELDS TERMINATED BY ';' 
            LINES TERMINATED BY '\n'
            IGNORE 3 LINES 
            (`compte`, `facture`, `abonne`, `date`, `heure`, `duree_volume_reel`, `duree_volume_facture`, `type`)", 
            addslashes($file_path), $this->file_table
        );

        $rows = $this->adapter->query($query, Adapter::QUERY_MODE_EXECUTE);

        if(!$rows) {
            throw new RuntimeException(sprintf(
                'Could not import data from %s',
                $file_path
            ));
        }

        return $rows;
    }

    /**
     * Truncate given table
     * @param $table_name
     * @return boolean
     */
    private function flushTable($table_name)
    {
        $query = sprintf("TRUNCATE %s", $table_name);
        return $this->adapter->query($query, Adapter::QUERY_MODE_EXECUTE);
    }

    /**
     * Fill 'appel' from 'file_import_contents'
     */
    private function fillContent()
    {
        $data = [];
        $cpt = 0;

        try{
            $rows = $this->getCorrectValue();

            foreach ($rows as $row)
            {
                if(!$row['abonne_id']){
                    continue;
                }

                $cpt++;

                $type = static::SMS;

                if($row['duree_appel'])
                {
                    $type = static::CALL;
                } 
                else if($row['volume_facture'])
                {
                    $type = static::DATA;
                }

                $data[] = [
                    'abonne_id' => $row['abonne_id'],
                    'date' => $row['date'],
                    'heure' => $row['heure'],
                    'duree_appel_reel' => $row['duree_appel'],
                    'volume_data_facture' => $row['volume_facture'],
                    'type' => $type
                ];

                if($cpt % static::MAX_VALUE === 0)
                {
                    $this->multiInsert($this->appel_table, $data);
                    $data = [];
                }
                
            }
            
            if($data)
            {
                $this->multiInsert($this->appel_table, $data);
            }
        }
        catch(RuntimeException $e) {

        }
        
        return $cpt;
    }

    /**
     * Fetch only valid data
     */
    private function getCorrectValue()
    {
        $query = "SELECT
                    `abonne` AS `abonne_id`,
                    DATE_FORMAT(STR_TO_DATE(`date`, '%d/%m/%Y'), '%Y-%m-%d') AS `date`,
                    TIME_FORMAT(`heure`, '%T') AS `heure`, 
                    IF(`duree_volume_reel` REGEXP '^([0-9]{2}):([0-9]{2}):([0-9]{2})$', TIME_FORMAT(`duree_volume_reel`, '%T'), null) AS `duree_appel`, 
                    IF(`duree_volume_facture` REGEXP '^([0-9]{2}):([0-9]{2}):([0-9]{2})$' OR `duree_volume_facture` = '', null, `duree_volume_facture`) AS `volume_facture`,
                    `type`
                FROM {$this->file_table}
                WHERE DATE(DATE_FORMAT(STR_TO_DATE(`date`, '%d/%m/%Y'), '%Y/%m/%d')) IS NOT NULL";

        $statement = $this->adapter->createStatement($query);
        
        return $statement->execute();
    }

    /**
     * Custom multi insert function
     * @param $table
     * @param $data
     * @return boolean
     */
    protected function multiInsert($table, array $data)
    {
        if (count($data)) {

            $columns = (array)current($data);
            $columns = array_keys($columns);
            $columnsCount = count($columns);

            $platform = $this->adapter->platform;

            array_filter($columns, function ($index, &$item) use ($platform) {
                $item = $platform->quoteIdentifier($item);
            });

            $columns = "(" . implode(',', $columns) . ")";

            $placeholder = array_fill(0, $columnsCount, '?');
            $placeholder = "(" . implode(',', $placeholder) . ")";
            $placeholder = implode(',', array_fill(0, count($data), $placeholder));

            $values = array();
            foreach ($data as $row) {
                foreach ($row as $key => $value) {
                    $values[] = $value;
                }
            }

            $table = $this->adapter->platform->quoteIdentifier($table);

            $q = "INSERT INTO $table $columns VALUES $placeholder";

            return $this->adapter->query($q)->execute($values);
        }
        return false;
    }

    public function getTotalAppelFrom($date)
    {
        $query = sprintf("SELECT SUM( TIME_TO_SEC( `duree_appel_reel` ) ) AS total_appel 
            FROM %s WHERE `date` >= %s", $this->appel_table ,$date);

        $statement = $this->adapter->createStatement($query);
        
        $row = $statement->execute()->current();
        
        return ($row)? $row['total_appel']:0;
    }

    public function getTotalSMS()
    {
        $query = sprintf("SELECT COUNT(`id`) AS total_sms 
            FROM %s WHERE `type` LIKE '%s'", $this->appel_table, static::SMS);

        $statement = $this->adapter->createStatement($query);
        
        $row = $statement->execute()->current();
        
        return ($row)? $row['total_sms']:0;
    }

    public function getTop10data()
    {
        $query = sprintf("SELECT abonne_id, volume_data_facture, heure
            FROM %s 
            WHERE 
                type LIKE '%s' AND 
                (HOUR(`heure`) < 8 OR HOUR(`heure`) >= 18)
            GROUP BY abonne_id 
            ORDER BY volume_data_facture DESC LIMIT 10", 
            $this->appel_table, static::DATA);

        $statement = $this->adapter->createStatement($query);
        
        return $statement->execute();
    }
}
