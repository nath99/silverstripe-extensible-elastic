<?php

namespace Symbiote\ElasticSearch;

use Elastica\Client;
use Elastica\Exception\Connection\HttpException;
use Heyday\Elastica\ElasticaService;
use Heyday\Elastica\ResultList;
use SilverStripe\Core\Injector\Injector;
use Symbiote\ElasticSearch\ElasticaQueryBuilder;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extensible;


/**
 * @author marcus
 */
class ExtensibleElasticService extends ElasticaService
{

    /**
     * A mapping of all the available query builders
     *
     * @var map
     */
    protected $queryBuilders = array();

    protected $buffered = false;

    protected $buffer = [];

    /**
     *
     * @var LoggerInterface
     */
    public $logger;

    /**
     * Overrides the parent indexName property so that we can
     * change the index based on the search / indexing context.
     *
     * We need this because the parent class has indexName as a private var
     */
    protected $customIndexName;

    /**
     * ElasticaService constructor.
     * @param Client $client
     * @param $indexName
     * @param LoggerInterface|null $logger Increases the memory limit while indexing. A memory limit string, such as "64M".
     * @param null $indexingMemory
     * @param string $searchableExtensionClassName
     */
    public function __construct(
        Client $client,
        $indexName,
        LoggerInterface $logger = null,
        $indexingMemory = null,
        $searchableExtensionClassName = Searchable::class
    )
    {
        parent::__construct($client, $indexName, $logger, $indexingMemory, $searchableExtensionClassName);
        $this->customIndexName = $indexName;
        $this->queryBuilders['default'] = ElasticaQueryBuilder::class;
    }

    /**
     * Override as parent class uses a private var
     */
    public function getIndex() {
        return $this->getClient()->getIndex($this->customIndexName);
    }

    public function setIndexName($name) {
        $this->customIndexName = $name;
    }

    /**
     * Gets the classes which are indexed (i.e. have the extension applied).
     *
     * @override due to the logic in the parent impl not being correct around extension inheritance
     *
     * @return array
     */
    public function getIndexedClasses()
    {
        $classes = array();
        foreach (ClassInfo::subclassesFor('SilverStripe\ORM\DataObject') as $candidate) {
            $candidateInstance = singleton($candidate);
            if (Extensible::has_extension($candidate, 'Heyday\\Elastica\\Searchable')) {
                $classes[] = $candidate;
            }
        }
        return $classes;
    }

    /**
     * Queries the elastic index using an elastic query, mapped as an array
     *
     * @param ElasticaQueryBuilder|string $query
     * @param int $offset
     * @param int $limit
     * @param string $resultClass
     * @return ResultList
     */
    public function query($query, $offset = 0, $limit = 20, $resultClass = '')
    {
        // check for _old_ param structure
        if (!$resultClass ||
            is_array($resultClass) ||
            is_string($resultClass) && !class_exists($resultClass)) {
            $resultClass = ResultList::class;
        }

        if ($query instanceof ElasticaQueryBuilder) {
            $elasticQuery = $query->toQuery();
        } else {
            $elasticQuery = $query;
        }

        $results = Injector::inst()->create($resultClass, $this->getIndex(), $elasticQuery, $this->logger);
		// The result list needs to be limited so the pagination is looking at the correct page.
        $results = $results->limit((int)$limit, (int)$offset);
        return $results;

    }

    public function isConnected()
    {
        return true;
    }

    /**
     * Gets the list of query parsers available
     *
     * @return array
     */
    public function getQueryBuilders()
    {
        return $this->queryBuilders;
    }

    /**
     * Gets the query builder for the given search type
     *
     * @param string $type
     * @return ElasticaQueryBuilder
     */
    public function getQueryBuilder($type = 'default')
    {
        return isset($this->queryBuilders[$type]) ? Injector::inst()->create($this->queryBuilders[$type]) : Injector::inst()->create($this->queryBuilders['default']);
    }

    /////////
    // Solr search compatibility layer
    /////////

    /**
     * Get all fields the particular class type can be searched on
     * @param string $listType
     */
    public function getAllSearchableFieldsFor($listType)
    {

    }

    public function getIndexFieldName($field, $classNames = array('Page'))
    {
        return $field;
    }

    public function getSortFieldName($sortBy, $types)
    {
        return $sortBy;
    }

    public function index($record)
    {
        if ($this->buffered) {
            $type = $record->getElasticaType();
            $document = $record->getElasticaDocument();
            if (array_key_exists($type, $this->buffer)) {
                $this->buffer[$type][] = $document;
            } else {
                $this->buffer[$type] = [$document];
            }
        } else {
            return parent::index($record);
        }
    }

    /**
     * Begins a bulk indexing operation where documents are buffered rather than
     * indexed immediately.
     */
    public function startBulkIndex()
    {
        $this->buffered = true;
    }
    /**
     * Ends the current bulk index operation and indexes the buffered documents.
     */
    public function endBulkIndex()
    {
        $index = $this->getIndex();
        try {
            foreach ($this->buffer as $type => $documents) {
                $index->addDocuments($documents);
                $index->refresh();
            }
        } catch (HttpException $ex) {
            $this->connected = false;
            // TODO LOG THIS ERROR
            error_log($ex->getMessage());
        } catch (\Elastica\Exception\BulkException $be) {
            error_log($be->getMessage());
            throw $be;
        }
        $this->buffered = false;
        $this->buffer = array();
    }

}
