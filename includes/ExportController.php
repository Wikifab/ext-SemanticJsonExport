<?php
namespace Semanticjsonexport;

use Title;
use Revision;
use WikiPage;
use Hooks;

/**
 * File holding the ExportController class that provides basic functions for
 * exporting pages.
 *
 * @ingroup SMW
 *
 * @author Markus Krötzsch
 */

/**
 * Class for controlling the export of SMW page data, supporting high-level
 * features such as recursive export and backlink inclusion. The class controls
 * export independent of the serialisation syntax that is used.
 *
 * @ingroup SMW
 */
class ExportController {
	const MAX_CACHE_SIZE = 5000; // do not let cache arrays get larger than this
	const CACHE_BACKJUMP = 500;  // kill this many cached entries if limit is reached,
	                             // avoids too much array copying; <= MAX_CACHE_SIZE!
	/**
	 * The object used for serialisation.
	 * @var SMWSerializer
	 */
	protected $serializer;
	/**
	 * An array that keeps track of the elements for which we still need to
	 * write auxiliary definitions/declarations.
	 */
	protected $element_queue;
	/**
	 * An array that keeps track of the recursion depth with which each object
	 * has been serialised.
	 */
	protected $element_done;
	/**
	 * Controls how long to wait until flushing content to output. Flushing
	 * early may reduce the memory footprint of serialization functions.
	 * Flushing later has some advantages for export formats like RDF/XML where
	 * global namespace declarations are only possible by modifying the header,
	 * so that only local declarations are possible after the first flush.
	 */
	protected $delay_flush;
	/**
	 * File handle for a potential output file to write to, or null if printing
	 * to standard output.
	 */
	protected $outputfile;

	protected $fieldsToParse = [];

	/**
	 * @var DeepRedirectTargetResolver
	 */
	private $deepRedirectTargetResolver = null;

	/**
	 * Constructor.
	 * @param SMWSerializer $serializer defining the object used for syntactic
	 * serialization.
	 */
	public function __construct( SerializerJson $serializer ) {
		$this->serializer = $serializer;
		$this->outputfile = null;
	}

	public function setFieldsToParse($fields) {

		$this->fieldsToParse = $fields;
	}

	/**
	 * Initialize all internal structures to begin with some serialization.
	 * Returns true if initialization was successful (this means that the
	 * optional output file is writable).
	 * @param string $outfilename URL of the file that output should be written
	 * to, or empty string for writting to the standard output.
	 *
	 * @return boolean
	 */
	protected function prepareSerialization( $outfilename = '' ) {
		$this->serializer->clear();
		$this->element_queue = array();
		$this->element_done = array();
		if ( $outfilename !== '' ) {
			$this->outputfile = fopen( $outfilename, 'w' );
			if ( !$this->outputfile ) { // TODO Rather throw an exception here.
				print "\nCannot open \"$outfilename\" for writing.\n";
				return false;
			}
		}
		return true;
	}

	/**
	 * Serialize data associated to a specific page. This method works on the
	 * level of pages, i.e. it serialises parts of SMW content and implements
	 * features like recursive export or backlinks that are available for this
	 * type of data.
	 *
	 * The recursion depth means the following. Depth of 1 or above means
	 * the object is serialised with all property values, and referenced
	 * objects are serialised with depth reduced by 1. Depth 0 means that only
	 * minimal declarations are serialised, so no dependencies are added. A
	 * depth of -1 encodes "infinite" depth, i.e. a complete recursive
	 * serialisation without limit.
	 *
	 * @param Title $title specifying the page to be exported
	 * @param integer $recursiondepth specifying the depth of recursion
	 */
	protected function serializePage( Title $title, $recursiondepth = 1 ) {
		$api = new \ApiMain(new \FauxRequest(['action' => 'ask', 'query' => "[[:".str_replace(" ", "_", $title->getText())."]]|?Complete", 'format' => 'json']), true);
		$api->execute();
		$data = $api->getResult()->getResultData();
		$displayTitle = $data['query']['results'][$title->getText()]['printouts']['Complete'][0];

		if ( $this->isPageDone( $title, $recursiondepth ) ) {
			return; // do not export twice
		}

		$this->markPageAsDone( $title, $recursiondepth );


		$page = WikiPage::factory( $title );

		$categoriesTitles = $page->getCategories();
		$categories = [];
		foreach ($categoriesTitles as $categoryTitle) {
			$categories[] = [
					'id' => $categoryTitle->getDBkey(),
					'name' => $categoryTitle->getText()
			];
		}

		$preloadContent = $page->getContent()->getWikitextForTransclusion();
		$creator = $page->getCreator();

		$pageInfo= [
				'creator' => $creator->getName(),
				'categories' => $categories,
				'Display title of' => $displayTitle
		];
		// remplace template :
		//$preloadContent  = str_replace('{{Tuto Details', '{{Tuto SearchResult', $preloadContent);

		// using semantic data, data are not organized by template, and some are missing, so its useless
		//$semData = $this->getSemanticData($title, ( $recursiondepth == 0 ));

		$data = $this->parseAllTemplatesFields($preloadContent);

		// Hook to allow other extension to change data, or add info :
		Hooks::run( 'SemanticJsonExportBeforeSerializePage', [ $title, &$data ]);

		//for fields : parse any wikitext :
		$this->parseWikitextFieldToHtml($data);

		$this->serializer->addPage($title, $pageInfo, $data);

	}

	protected function parseWikitextFieldToHtml(& $data) {

		if (! $this->fieldsToParse) {
			return;
		}
		foreach ($data as $key => $val) {
			if($val && is_string($val) && in_array($key, $this->fieldsToParse)) {
				$data[$key] = WikitextParser::parse($val);
			} else if(is_array($val)) {
				$this->parseWikitextFieldToHtml($data[$key]);
			}
		}
	}

	protected function parseAllTemplatesFields($pageContent) {

		// TODO : get thoses templates names dynamically
		$templateToParse = [
				'Tuto Details',
				'Introduction',
				'Materials',
				'Tuto Step',
				'Notes',
				'VideoIntro',
				'WikiPage',
				'PropertiesList',
				'PropertyOptions'
		];
		$multipleTemplates = [
				'Tuto Step',
				'PropertiesList',
				'PropertyOptions'
		];
		$result = [];
		foreach ($templateToParse as $templateName) {
			$data = $this->parseTemplateFields($templateName, $pageContent, in_array($templateName, $multipleTemplates));
			$result[$templateName] = $data;
		}
		return $result;

	}

	/**
	 * this function look for templates inside page content,
	 * and return fields values in thoses templates
	 *
	 * @param string $templateName
	 * @param string $pageContent
	 * @return Array
	 */
	protected function parseTemplateFields($templateName, $pageContent, $isMultiple = true) {
		global $wgParser;
		// set this is required, because parser is called in pageforms function,
		// and it must be initialized first
		$options = new \ParserOptions();
		$wgParser->startExternalParse($title = null, $options, \Parser::OT_HTML, $clearState = true);

		$search_pattern = '/{{' . $templateName . '\s*[\|}]/i';

		$result = [ ];
		while ( preg_match ( $search_pattern, $pageContent, $matches, PREG_OFFSET_CAPTURE ) ) {
			$startIndex = $matches [0] [1];

			// get fields for template using PageForm librairies :
			$tif = \PFTemplateInForm::newFromFormTag ( [
					'Form',
					$templateName
			] );
			$tif->setPageRelatedInfo ( $pageContent );
			$tif->setFieldValuesFromPage ( substr ( $pageContent, $startIndex ) );
			$data = $tif->getValuesFromPage ();

			// remove parsed part, and check for the next one :
			$templateLength = strlen ( $tif->getFullTextInPage () );
			$pageContent = substr ( $pageContent, $startIndex + $templateLength );

			$result [] = $data;
			if ( ! $isMultiple) {
				return $data;
			}
		}
		return $result;
	}

	/**
	 * Add a given Title to the export queue if needed.
	 */
	protected function queuePage( Title $page, $recursiondepth ) {
		// TODO : manage $recursiondepth ?
		if ( !$this->isPageDone( $page, $recursiondepth ) && ! isset($this->element_queue[$page->getFullText()])) {
			$this->element_queue[$page->getFullText()] = $page;
		}
	}

	/**
	 * Mark an article as done while making sure that the cache used for this
	 * stays reasonably small. Input is given as an Title object.
	 */
	protected function markPageAsDone( Title $page, $recdepth ) {
		$this->markHashAsDone( $page->getFullText(), $recdepth );
	}

	/**
	 * Mark a task as done while making sure that the cache used for this
	 * stays reasonably small.
	 */
	protected function markHashAsDone( $hash, $recdepth ) {
		if ( count( $this->element_done ) >= self::MAX_CACHE_SIZE ) {
			$this->element_done = array_slice( $this->element_done,
				self::CACHE_BACKJUMP,
				self::MAX_CACHE_SIZE - self::CACHE_BACKJUMP,
				true );
		}
		if ( !$this->isHashDone( $hash, $recdepth ) ) {
			$this->element_done[$hash] = $recdepth; // mark title as done, with given recursion
		}
		unset( $this->element_queue[$hash] ); // make sure it is not in the queue
	}

	/**
	 * Check if the given object has already been serialised at sufficient
	 * recursion depth.
	 * @param Title $st specifying the object to check
	 *
	 * @return boolean
	 */
	protected function isPageDone( Title $page, $recdepth ) {
		return $this->isHashDone( $page->getFullText(), $recdepth );
	}

	/**
	 * Check if the given task has already been completed at sufficient
	 * recursion depth.
	 */
	protected function isHashDone( $hash, $recdepth ) {
		return ( ( array_key_exists( $hash, $this->element_done ) ) &&
		         ( ( $this->element_done[$hash] == -1 ) ||
		           ( ( $recdepth != -1 ) && ( $this->element_done[$hash] >= $recdepth ) ) ) );
	}


	/**
	 * Send to the output what has been serialized so far. The flush might
	 * be deferred until later unless $force is true.
	 */
	protected function flush( $force = false ) {
		if ( !$force && ( $this->delay_flush > 0 ) ) {
			$this->delay_flush -= 1;
		} elseif ( !is_null( $this->outputfile ) ) {
			fwrite( $this->outputfile, $this->serializer->flushContent() );
		} else {
			ob_start();
			print $this->serializer->flushContent();
			// Ship data in small chunks (even though browsers often do not display anything
			// before the file is complete -- this might be due to syntax highlighting features
			// for app/xml). You may want to sleep(1) here for debugging this.
			ob_flush();
			flush();
			ob_get_clean();
		}
	}

	/**
	 * This function prints all selected pages, specified as an array of page
	 * names (strings with namespace identifiers).
	 *
	 * @param array $pages list of page names to export
	 * @param integer $recursion determines how pages are exported recursively:
	 * "0" means that referenced resources are only declared briefly, "1" means
	 * that all referenced resources are also exported recursively (propbably
	 * retrieving the whole wiki).
	 * @param string $revisiondate filter page list by including only pages
	 * that have been changed since this date; format "YmdHis"
	 *
	 * @todo Consider dropping the $revisiondate filtering and all associated
	 * functionality. Is anybody using this?
	 */
	public function printPages( $pages, $recursion = 1, $revisiondate = false  ) {

		$linkCache = \LinkCache::singleton();
		$this->prepareSerialization();
		$this->delay_flush = 10; // flush only after (fully) printing 11 objects

		// transform pages into queued short titles
		foreach ( $pages as $page ) {
			if($page instanceof Title) {
				$title = $page;
			} else {
				$title = Title::newFromText( $page );
			}
			if ( null === $title ) {
				continue; // invalid title name given
			}
			if ( $revisiondate !== '' ) { // filter page list by revision date
				$rev = Revision::getTimeStampFromID( $title, $title->getLatestRevID() );
				if ( $rev < $revisiondate ) {
					continue;
				}
			}

			$this->queuePage( $title, ( $recursion==1 ? -1 : 1 ) );
		}

		$this->serializer->startSerialization();

		while ( count( $this->element_queue ) > 0 ) {
			$diPage = reset( $this->element_queue );
			$this->serializePage( $diPage );
			$this->flush();
			$linkCache->clear(); // avoid potential memory leak
		}
		$this->serializer->finishSerialization();
		$this->flush( true );

	}

	/**
	 * Exports semantic data for all pages within the wiki and for all elements
	 * that are referred to a file resource
	 *
	 * @since  2.0
	 *
	 * @param string $outfile the output file URI, or false if printing to stdout
	 * @param mixed $ns_restriction namespace restriction, see fitsNsRestriction()
	 * @param integer $delay number of microseconds for which to sleep during
	 * export to reduce server load in long-running operations
	 * @param integer $delayeach number of pages to process between two sleeps
	 */
	public function printAllToFile( $outfile, $ns_restriction = false, $delay, $delayeach ) {

		if ( !$this->prepareSerialization( $outfile ) ) {
			return;
		}

		$this->printAll( $ns_restriction, $delay, $delayeach );
	}

	/**
	 * Exports semantic data for all pages within the wiki and for all elements
	 * that are referred to the stdout
	 *
	 * @since  2.0
	 *
	 * @param mixed $ns_restriction namespace restriction, see fitsNsRestriction()
	 * @param integer $delay number of microseconds for which to sleep during
	 * export to reduce server load in long-running operations
	 * @param integer $delayeach number of pages to process between two sleeps
	 */
	public function printAllToOutput( $ns_restriction = false, $delay, $delayeach ) {
		$this->prepareSerialization();
		$this->printAll( $ns_restriction, $delay, $delayeach );
	}

	/**
	 * @since 2.0 made protected; use printAllToFile or printAllToOutput
	 */
	protected function printAll( $ns_restriction = false, $delay, $delayeach ) {
		$linkCache = LinkCache::singleton();
		$db = wfGetDB( DB_SLAVE );

		$this->delay_flush = 10;

		$this->serializer->startSerialization();
		$this->serializer->serializeExpData( SMWExporter::getInstance()->getOntologyExpData( '' ) );

		$end = $db->selectField( 'page', 'max(page_id)', false, __METHOD__ );
		$a_count = 0; // DEBUG
		$d_count = 0; // DEBUG
		$delaycount = $delayeach;

		for ( $id = 1; $id <= $end; $id += 1 ) {
			$title = Title::newFromID( $id );
			if ( is_null( $title ) || !smwfIsSemanticsProcessed( $title->getNamespace() ) ) {
				continue;
			}
			if ( !self::fitsNsRestriction( $ns_restriction, $title->getNamespace() ) ) {
				continue;
			}
			$a_count += 1; // DEBUG

			$this->queuePage( $title, 1 );

			while ( count( $this->element_queue ) > 0 ) {
				$diPage = reset( $this->element_queue );
				$this->serializePage( $diPage, $diPage->recdepth );
				// resolve dependencies that will otherwise not be printed
				foreach ( $this->element_queue as $key => $diaux ) {
					if ( !smwfIsSemanticsProcessed( $diaux->getNamespace() ) ||
					     !self::fitsNsRestriction( $ns_restriction, $diaux->getNamespace() ) ) {
						// Note: we do not need to check the cache to guess if an element was already
						// printed. If so, it would not be included in the queue in the first place.
						$d_count += 1; // DEBUG
					} else { // don't carry values that you do not want to export (yet)
						unset( $this->element_queue[$key] );
					}
				}
				// sleep each $delaycount for $delay µs to be nice to the server
				if ( ( $delaycount-- < 0 ) && ( $delayeach != 0 ) ) {
					usleep( $delay );
					$delaycount = $delayeach;
				}
			}

			$this->flush();
			$linkCache->clear();
		}

		$this->serializer->finishSerialization();
		$this->flush( true );
	}

	/**
	 * Print basic definitions a list of pages ordered by their page id.
	 * Offset and limit refer to the count of existing pages, not to the
	 * page id.
	 * @param integer $offset the number of the first (existing) page to
	 * serialize a declaration for
	 * @param integer $limit the number of pages to serialize
	 */
	public function printPageList( $offset = 0, $limit = 30 ) {
		global $smwgNamespacesWithSemanticLinks;

		$db = wfGetDB( DB_SLAVE );
		$this->prepareSerialization();
		$this->delay_flush = 35; // don't do intermediate flushes with default parameters
		$linkCache = LinkCache::singleton();

		$this->serializer->startSerialization();
		$this->serializer->serializeExpData( SMWExporter::getInstance()->getOntologyExpData( '' ) );

		$query = '';
		foreach ( $smwgNamespacesWithSemanticLinks as $ns => $enabled ) {
			if ( $enabled ) {
				if ( $query !== '' ) {
					$query .= ' OR ';
				}
				$query .= 'page_namespace = ' . $db->addQuotes( $ns );
			}
		}
		$res = $db->select( $db->tableName( 'page' ),
		                    'page_id,page_title,page_namespace', $query,
		                    'SMW::RDF::PrintPageList', array( 'ORDER BY' => 'page_id ASC', 'OFFSET' => $offset, 'LIMIT' => $limit ) );
		$foundpages = false;

		foreach ( $res as $row ) {
			$foundpages = true;
			try {
				$title = Title::newFromText( $row->page_title,$row->page_namespace );
				$this->serializePage( $title, 0 );
				$this->flush();
				$linkCache->clear();
			} catch ( SMWDataItemException $e ) {
				// strange data, who knows, not our DB table, keep calm and carry on
			}
		}

		if ( $foundpages ) { // add link to next result page
			if ( strpos( SMWExporter::getInstance()->expandURI( '&wikiurl;' ), '?' ) === false ) { // check whether we have title as a first parameter or in URL
				$nexturl = SMWExporter::getInstance()->expandURI( '&export;?offset=' ) . ( $offset + $limit );
			} else {
				$nexturl = SMWExporter::getInstance()->expandURI( '&export;&amp;offset=' ) . ( $offset + $limit );
			}

			$expData = new SMWExpData( new SMWExpResource( $nexturl ) );
			$ed = new SMWExpData( SMWExporter::getInstance()->getSpecialNsResource( 'owl', 'Thing' ) );
			$expData->addPropertyObjectValue( SMWExporter::getInstance()->getSpecialNsResource( 'rdf', 'type' ), $ed );
			$ed = new SMWExpData( new SMWExpResource( $nexturl ) );
			$expData->addPropertyObjectValue( SMWExporter::getInstance()->getSpecialNsResource( 'rdfs', 'isDefinedBy' ), $ed );
			$this->serializer->serializeExpData( $expData );
		}

		$this->serializer->finishSerialization();
		$this->flush( true );

	}


	/**
	 * Print basic information about this site.
	 */
	public function printWikiInfo() {

		global $wgSitename, $wgLanguageCode;

		$this->prepareSerialization();
		$this->delay_flush = 35; // don't do intermediate flushes with default parameters

		// assemble export data:
		$expData = new SMWExpData( new SMWExpResource( '&wiki;#wiki' ) );

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'rdf', 'type' ),
			new SMWExpData( SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'Wikisite' ) )
		);

		// basic wiki information
		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'rdfs', 'label' ),
			new SMWExpLiteral( $wgSitename )
		);

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'siteName' ),
			new SMWExpLiteral( $wgSitename, 'http://www.w3.org/2001/XMLSchema#string' )
		);

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'pagePrefix' ),
			new SMWExpLiteral( SMWExporter::getInstance()->expandURI( '&wikiurl;' ), 'http://www.w3.org/2001/XMLSchema#string' )
		);

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'smwVersion' ),
			new SMWExpLiteral( SMW_VERSION, 'http://www.w3.org/2001/XMLSchema#string' )
		);

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'langCode' ),
			new SMWExpLiteral( $wgLanguageCode, 'http://www.w3.org/2001/XMLSchema#string' )
		);

		$mainpage = Title::newMainPage();

		if ( !is_null( $mainpage ) ) {
			$ed = new SMWExpData( new SMWExpResource( $mainpage->getFullURL() ) );
			$expData->addPropertyObjectValue( SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'mainPage' ), $ed );
		}

		// statistical information
		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'pageCount' ),
			new SMWExpLiteral( SiteStats::pages(), 'http://www.w3.org/2001/XMLSchema#int' )
		);

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'contentPageCount' ),
			new SMWExpLiteral( SiteStats::articles(), 'http://www.w3.org/2001/XMLSchema#int' )
		);

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'mediaCount' ),
			new SMWExpLiteral( SiteStats::images(), 'http://www.w3.org/2001/XMLSchema#int' )
		);

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'editCount' ),
			new SMWExpLiteral( SiteStats::edits(), 'http://www.w3.org/2001/XMLSchema#int' )
		);

		// SiteStats::views was deprecated in MediaWiki 1.25
		// "Stop calling this function, it will be removed some time in the future"
		//$expData->addPropertyObjectValue(
		//	SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'viewCount' ),
		//	new SMWExpLiteral( SiteStats::views(), 'http://www.w3.org/2001/XMLSchema#int' )
		//);

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'userCount' ),
			new SMWExpLiteral( SiteStats::users(), 'http://www.w3.org/2001/XMLSchema#int' )
		);

		$expData->addPropertyObjectValue(
			SMWExporter::getInstance()->getSpecialNsResource( 'swivt', 'adminCount' ),
			new SMWExpLiteral( SiteStats::numberingroup( 'sysop' ), 'http://www.w3.org/2001/XMLSchema#int' )
		);

		$this->serializer->startSerialization();
		$this->serializer->serializeExpData( SMWExporter::getInstance()->getOntologyExpData( '' ) );
		$this->serializer->serializeExpData( $expData );

		// link to list of existing pages:
		if ( strpos( SMWExporter::getInstance()->expandURI( '&wikiurl;' ), '?' ) === false ) { // check whether we have title as a first parameter or in URL
			$nexturl = SMWExporter::getInstance()->expandURI( '&export;?offset=0' );
		} else {
			$nexturl = SMWExporter::getInstance()->expandURI( '&export;&amp;offset=0' );
		}
		$expData = new SMWExpData( new SMWExpResource( $nexturl ) );
		$ed = new SMWExpData( SMWExporter::getInstance()->getSpecialNsResource( 'owl', 'Thing' ) );
		$expData->addPropertyObjectValue( SMWExporter::getInstance()->getSpecialNsResource( 'rdf', 'type' ), $ed );
		$ed = new SMWExpData( new SMWExpResource( $nexturl ) );
		$expData->addPropertyObjectValue( SMWExporter::getInstance()->getSpecialNsResource( 'rdfs', 'isDefinedBy' ), $ed );
		$this->serializer->serializeExpData( $expData );

		$this->serializer->finishSerialization();
		$this->flush( true );

	}

	/**
	 * This function checks whether some article fits into a given namespace
	 * restriction. Restrictions are encoded as follows: a non-negative number
	 * requires the namespace to be identical to the given number; "-1"
	 * requires the namespace to be different from Category, Property, and
	 * Type; "false" means "no restriction".
	 *
	 * @param $res mixed encoding the restriction as described above
	 * @param $ns integer the namespace constant to be checked
	 *
	 * @return boolean
	 */
	static public function fitsNsRestriction( $res, $ns ) {
		if ( $res === false ) {
			return true;
		}
		if ( is_array( $res ) ) {
			return in_array( $ns, $res );
		}
		if ( $res >= 0 ) {
			return ( $res == $ns );
		}
		return ( ( $res != NS_CATEGORY ) && ( $res != SMW_NS_PROPERTY ) && ( $res != SMW_NS_TYPE ) );
	}

	private function getDeepRedirectTargetResolver() {

		if ( $this->deepRedirectTargetResolver === null ) {
			$this->deepRedirectTargetResolver = ApplicationFactory::getInstance()->newMwCollaboratorFactory()->newDeepRedirectTargetResolver();
		}

		return $this->deepRedirectTargetResolver;
	}

}
