<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */
namespace Piwik;

use Exception;
use Piwik\ArchiveProcessor\Parameters;

use Piwik\DataAccess\ArchiveWriter;
use Piwik\DataAccess\LogAggregator;
use Piwik\DataTable\Manager;
use Piwik\DataTable\Map;
use Piwik\DataTable\Row;
use Piwik\Db;
use Piwik\Period;

/**
 * Used to insert numeric and blob archive data, and to aggregate archive data.
 *
 * During the Archiving process an instance of this class is used by plugins
 * to cache aggregated analytics statistics.
 * 
 * When the {@link Archive} class is used to query for archive data and that archive
 * data is found to be absent, the archiving process is launched. Instances of the
 * {@link Piwik\Plugin\Archiver} classes for every plugin that supplies one are
 * then used to execute archiving logic.
 * 
 * Plugins access ArchiveProcessor instances through the {@link Piwik\Plugin\Archiver} class.
 * Read the docs for {@link Piwik\Plugin\Archiver} to learn more about the process.
 * 
 * ### Limitations
 * 
 * - It is currently only possible to aggregate statistics for one site and period
 * at a time. The archive.php cron script does, however, issue asynchronous HTTP
 * requests that initiate archiving, so statistics can be calculated in parallel.
 * 
 * ### See also
 * 
 * - **{@link Piwik\Plugin\Archiver}** - to learn how plugins should implement their own analytics
 *                                       aggregation logic.
 * - **{@link Piwik\DataAccess\LogAggregator}** - to learn how plugins can perform data aggregation
 *                                                across Piwik's log tables.
 * 
 * ### Examples
 * 
 * **Inserting numeric data**
 * 
 *     // function in an Archiver descendent
 *     public function aggregateDayReport()
 *     {
 *         $archiveProcessor = $this->getProcessor();
 * 
 *         $myFancyMetric = // ... calculate the metric value ...
 *         $archiveProcessor->insertNumericRecord('MyPlugin_myFancyMetric', $myFancyMetric);
 *     }
 * 
 * **Inserting serialized DataTables**
 * 
 *     // function in an Archiver descendent
 *     public function aggregateDayReport()
 *     {
 *         $archiveProcessor = $this->getProcessor();
 * 
 *         $maxRowsInTable = Config::getInstance()->General['datatable_archiving_maximum_rows_standard'];j
 * 
 *         $dataTable = // ... build by aggregating visits ...
 *         $serializedData = $dataTable->getSerialized($maxRowsInTable, $maxRowsInSubtable = $maxRowsInTable,
 *                                                     $columnToSortBy = Metrics::INDEX_NB_VISITS);
 *         
 *         $archiveProcessor->insertBlobRecords('MyPlugin_myFancyReport', $serializedData);
 *     }
 * 
 * **Aggregating archive data**
 * 
 *     // function in Archiver descendent
 *     public function aggregateMultipleReports()
 *     {
 *         $archiveProcessor = $this->getProcessor();
 * 
 *         // aggregate a metric
 *         $archiveProcessor->aggregateNumericMetrics('MyPlugin_myFancyMetric');
 *         $archiveProcessor->aggregateNumericMetrics('MyPlugin_mySuperFancyMetric', 'max');
 * 
 *         // aggregate a report        
 *         $archiveProcessor->aggregateDataTableRecords('MyPlugin_myFancyReport');
 *     }
 * 
 * @package Piwik
 * @subpackage ArchiveProcessor
 */
class ArchiveProcessor
{
    /**
     * @var \Piwik\DataAccess\ArchiveWriter
     */
    protected $archiveWriter;

    /**
     * @var \Piwik\DataAccess\LogAggregator
     */
    protected $logAggregator;

    /**
     * @var Archive
     */
    public $archive = null;

    /**
     * @var Parameters
     */
    protected $params;

    /**
     * @var int
     */
    protected $numberOfVisits = false;
    protected $numberOfVisitsConverted = false;

    public function __construct(Parameters $params, ArchiveWriter $archiveWriter)
    {
        $this->params = $params;
        $this->logAggregator = new LogAggregator($params);
        $this->archiveWriter = $archiveWriter;
    }

    protected function getArchive()
    {
        if(empty($this->archive)) {
            $subPeriods = $this->params->getSubPeriods();
            $idSites = $this->params->getIdSites();
            $this->archive = Archive::factory($this->params->getSegment(), $subPeriods, $idSites);
        }
        return $this->archive;
    }

    public function setNumberOfVisits($visits, $visitsConverted)
    {
        $this->numberOfVisits = $visits;
        $this->numberOfVisitsConverted = $visitsConverted;
    }

    /**
     * Returns the Parameters object containing the site, period and segment used with this archive.
     *
     * @return Parameters
     * @api
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Returns a `{@link Piwik\DataAccess\LogAggregator}` instance for the site, period and segment this
     * ArchiveProcessor will insert archive data for.
     *
     * @return LogAggregator
     * @api
     */
    public function getLogAggregator()
    {
        return $this->logAggregator;
    }

    /**
     * Array of (column name before => column name renamed) of the columns for which sum operation is invalid.
     * These columns will be renamed as per this mapping.
     * @var array
     */
    protected static $columnsToRenameAfterAggregation = array(
        Metrics::INDEX_NB_UNIQ_VISITORS => Metrics::INDEX_SUM_DAILY_NB_UNIQ_VISITORS
    );

    /**
     * Sums records for every subperiod of the current period and inserts the result as the record
     * for this period.
     *
     * DataTables are summed recursively so subtables will be summed as well.
     *
     * @param string|array $recordNames Name(s) of the report we are aggregating, eg, `'Referrers_type'`.
     * @param int $maximumRowsInDataTableLevelZero Maximum number of rows allowed in the top level DataTable.
     * @param int $maximumRowsInSubDataTable Maximum number of rows allowed in each subtable.
     * @param string $columnToSortByBeforeTruncation The name of the column to sort by before truncating a DataTable.
     * @param array $columnsAggregationOperation Operations for aggregating columns, @see Row::sumRow().
     * @param array $columnsToRenameAfterAggregation For columns that must change names when summed because they
     *                                                    cannot be summed, eg,
     *                                                    `array('nb_uniq_visitors' => 'sum_daily_nb_uniq_visitors')`.
     * @return array Returns the row counts of each aggregated report before truncation, eg,
     *               ```
     *               array(
     *                   'report1' => array('level0' => $report1->getRowsCount,
     *                                      'recursive' => $report1->getRowsCountRecursive()),
     *                   'report2' => array('level0' => $report2->getRowsCount,
     *                                      'recursive' => $report2->getRowsCountRecursive()),
     *                   ...
     *               )
     *               ```
     * @api
     */
    public function aggregateDataTableRecords($recordNames,
                                              $maximumRowsInDataTableLevelZero = null,
                                              $maximumRowsInSubDataTable = null,
                                              $columnToSortByBeforeTruncation = null,
                                              &$columnsAggregationOperation = null,
                                              $columnsToRenameAfterAggregation = null)
    {
        // We clean up below all tables created during this function call (and recursive calls)
        $latestUsedTableId = Manager::getInstance()->getMostRecentTableId();
        if (!is_array($recordNames)) {
            $recordNames = array($recordNames);
        }
        $nameToCount = array();
        foreach ($recordNames as $recordName) {
            $table = $this->aggregateDataTableRecord($recordName, $columnsAggregationOperation, $columnsToRenameAfterAggregation);

            $nameToCount[$recordName]['level0'] = $table->getRowsCount();
            $nameToCount[$recordName]['recursive'] = $table->getRowsCountRecursive();

            $blob = $table->getSerialized($maximumRowsInDataTableLevelZero, $maximumRowsInSubDataTable, $columnToSortByBeforeTruncation);
            Common::destroy($table);
            $this->insertBlobRecord($recordName, $blob);
        }
        Manager::getInstance()->deleteAll($latestUsedTableId);

        return $nameToCount;
    }

    /**
     * Aggregates one or more metrics for every subperiod of the current period and inserts the results
     * as metrics for the current period.
     *
     * @param array|string $columns Array of metric names to aggregate.
     * @param bool|string $operationToApply The operation to apply to the metric. Either `'sum'`, `'max'` or `'min'`.
     * @return array|int Returns the array of aggregate values. If only one metric was aggregated,
     *                   the aggregate value will be returned as is, not in an array.
     *                   For example, if `array('nb_visits', 'nb_hits')` is supplied for `$columns`,
     *                   ```
     *                   array(
     *                       'nb_visits' => 3040,
     *                       'nb_hits' => 405
     *                   )
     *                   ```
     *                   could be returned. If `array('nb_visits')` or `'nb_visits'` is used for `$columns`,
     *                   then `3040` would be returned.
     * @api
     */
    public function aggregateNumericMetrics($columns, $operationToApply = false)
    {
        $metrics = $this->getAggregatedNumericMetrics($columns, $operationToApply);

        foreach($metrics as $column => $value) {
            $this->archiveWriter->insertRecord($column, $value);
        }
        // if asked for only one field to sum
        if (count($metrics) == 1) {
            return reset($metrics);
        }

        // returns the array of records once summed
        return $metrics;
    }

    public function getNumberOfVisits()
    {
        if($this->numberOfVisits === false) {
            throw new Exception("visits should have been set here");
        }
        return $this->numberOfVisits;
    }

    public function getNumberOfVisitsConverted()
    {
        return $this->numberOfVisitsConverted;
    }

    /**
     * Caches multiple numeric records in the archive for this processor's site, period
     * and segment.
     *
     * @param array $numericRecords A name-value mapping of numeric values that should be
     *                              archived, eg,
     *                              ```
     *                              array('Referrers_distinctKeywords' => 23, 'Referrers_distinctCampaigns' => 234)
     *                              ```
     * @api
     */
    public function insertNumericRecords($numericRecords)
    {
        foreach ($numericRecords as $name => $value) {
            $this->insertNumericRecord($name, $value);
        }
    }

    /**
     * Caches a single numeric record in the archive for this processor's site, period and
     * segment.
     *
     * Numeric values are not inserted if they equal 0.
     *
     * @param string $name The name of the numeric value, eg, `'Referrers_distinctKeywords'`.
     * @param float $value The numeric value.
     * @api
     */
    public function insertNumericRecord($name, $value)
    {
        $value = round($value, 2);
        $this->archiveWriter->insertRecord($name, $value);
    }

    /**
     * Caches one or more blob records in the archive for this processor's site, period
     * and segment.
     *
     * @param string $name The name of the record, eg, 'Referrers_type'.
     * @param string|array $values A blob string or an array of blob strings. If an array
     *                             is used, the first element in the array will be inserted
     *                             with the `$name` name. The others will be inserted with
     *                             `$name . '_' . $index` as the record name (where $index is
     *                             the index of the blob record in `$values`).
     * @api
     */
    public function insertBlobRecord($name, $values)
    {
        $this->archiveWriter->insertBlobRecord($name, $values);
    }

    /**
     * This method selects all DataTables that have the name $name over the period.
     * All these DataTables are then added together, and the resulting DataTable is returned.
     *
     * @param string $name
     * @param array $columnsAggregationOperation Operations for aggregating columns, @see Row::sumRow()
     * @param array $columnsToRenameAfterAggregation columns in the array (old name, new name) to be renamed as the sum operation is not valid on them (eg. nb_uniq_visitors->sum_daily_nb_uniq_visitors)
     * @return DataTable
     */
    protected function aggregateDataTableRecord($name, $columnsAggregationOperation = null, $columnsToRenameAfterAggregation = null)
    {
        $dataTable = $this->getArchive()->getDataTableExpanded($name, $idSubTable = null, $depth = null, $addMetadataSubtableId = false);
        $dataTable = $this->getAggregatedDataTableMap($dataTable, $columnsAggregationOperation);
        $this->renameColumnsAfterAggregation($dataTable, $columnsToRenameAfterAggregation);
        return $dataTable;
    }

    protected function getOperationForColumns($columns, $defaultOperation)
    {
        $operationForColumn = array();
        foreach ($columns as $name) {
            $operation = $defaultOperation;
            if (empty($operation)) {
                $operation = $this->guessOperationForColumn($name);
            }
            $operationForColumn[$name] = $operation;
        }
        return $operationForColumn;
    }

    protected function enrichWithUniqueVisitorsMetric(Row $row)
    {
        if(!$this->getParams()->isSingleSite() ) {
            // we only compute unique visitors for a single site
            return;
        }
        if ( $row->getColumn('nb_uniq_visitors') !== false) {
            if (SettingsPiwik::isUniqueVisitorsEnabled($this->getParams()->getPeriod()->getLabel())) {
                $uniqueVisitors = (float)$this->computeNbUniqVisitors();
                $row->setColumn('nb_uniq_visitors', $uniqueVisitors);
            } else {
                $row->deleteColumn('nb_uniq_visitors');
            }
        }
    }

    protected function guessOperationForColumn($column)
    {
        if (strpos($column, 'max_') === 0) {
            return 'max';
        }
        if (strpos($column, 'min_') === 0) {
            return 'min';
        }
        return 'sum';
    }

    /**
     * Processes number of unique visitors for the given period
     *
     * This is the only Period metric (ie. week/month/year/range) that we process from the logs directly,
     * since unique visitors cannot be summed like other metrics.
     *
     * @return int
     */
    protected function computeNbUniqVisitors()
    {
        $logAggregator = $this->getLogAggregator();
        $query = $logAggregator->queryVisitsByDimension(array(), false, array(), array(Metrics::INDEX_NB_UNIQ_VISITORS));
        $data = $query->fetch();
        return $data[Metrics::INDEX_NB_UNIQ_VISITORS];
    }

    /**
     * If the DataTable is a Map, sums all DataTable in the map and return the DataTable.
     *
     *
     * @param $data DataTable|DataTable\Map
     * @param $columnsToRenameAfterAggregation array
     * @return DataTable
     */
    protected function getAggregatedDataTableMap($data, $columnsAggregationOperation)
    {
        $table = new DataTable();
        if (!empty($columnsAggregationOperation)) {
            $table->setMetadata(DataTable::COLUMN_AGGREGATION_OPS_METADATA_NAME, $columnsAggregationOperation);
        }
        if ($data instanceof DataTable\Map) {
            // as $date => $tableToSum
            $this->aggregatedDataTableMapsAsOne($data, $table);
        } else {
            $table->addDataTable($data);
        }
        return $table;
    }

    /**
     * Aggregates the DataTable\Map into the destination $aggregated
     * @param $map
     * @param $aggregated
     */
    protected function aggregatedDataTableMapsAsOne(Map $map, DataTable $aggregated)
    {
        foreach ($map->getDataTables() as $tableToAggregate) {
            if($tableToAggregate instanceof Map) {
                $this->aggregatedDataTableMapsAsOne($tableToAggregate, $aggregated);
            } else {
                $aggregated->addDataTable($tableToAggregate);
            }
        }
    }

    protected function renameColumnsAfterAggregation(DataTable $table, $columnsToRenameAfterAggregation = null)
    {
        // Rename columns after aggregation
        if (is_null($columnsToRenameAfterAggregation)) {
            $columnsToRenameAfterAggregation = self::$columnsToRenameAfterAggregation;
        }
        foreach ($columnsToRenameAfterAggregation as $oldName => $newName) {
            $table->renameColumn($oldName, $newName);
        }
    }

    protected function getAggregatedNumericMetrics($columns, $operationToApply)
    {
        if (!is_array($columns)) {
            $columns = array($columns);
        }
        $operationForColumn = $this->getOperationForColumns($columns, $operationToApply);

        $dataTable = $this->getArchive()->getDataTableFromNumeric($columns);

        $results = $this->getAggregatedDataTableMap($dataTable, $operationForColumn);
        if ($results->getRowsCount() > 1) {
            throw new Exception("A DataTable is an unexpected state:" . var_export($results, true));
        }

        $rowMetrics = $results->getFirstRow();
        if($rowMetrics === false) {
            $rowMetrics = new Row;
        }
        $this->enrichWithUniqueVisitorsMetric($rowMetrics);
        $this->renameColumnsAfterAggregation($results);

        $metrics = $rowMetrics->getColumns();

        foreach ($columns as $name) {
            if (!isset($metrics[$name])) {
                $metrics[$name] = 0;
            }
        }
        return $metrics;
    }
}