<?php
namespace Semanticjsonexport;

use SpecialPage;
use Title;


/**
 * This special page (Special:ExportRDF) for MediaWiki implements an OWL-export of semantic data,
 * gathered both from the annotations in articles, and from metadata already
 * present in the database.
 *
 * @ingroup SMWSpecialPage
 * @ingroup SpecialPage
 *
 * @author Markus Krötzsch
 * @author Jeroen De Dauw
 */
class SpecialExport extends SpecialPage {

	/// Export controller object to be used for serializing data
	protected $export_controller;

	public function __construct() {
		parent::__construct( 'ExportSemanticJson' );
	}

	public function execute( $page ) {
		$this->setHeaders();
		global $wgOut, $wgRequest;

		$wgOut->setPageTitle( wfMessage( 'exportjson' )->text() );

		// see if we can find something to export:
		$page = is_null( $page ) ? $wgRequest->getVal( 'page' ) : rawurldecode( $page );
		$pages = false;

		if ( !is_null( $page ) || $wgRequest->getCheck( 'page' ) ) {
			$page = is_null( $page ) ? $wgRequest->getCheck( 'text' ) : $page;

			if ( $page !== '' ) {
				$pages = array( $page );
			}
		}

		if ( $pages === false && $wgRequest->getCheck( 'pages' ) ) {
			$pageBlob = $wgRequest->getText( 'pages' );

			if ( $pageBlob !== '' ) {
				$pages = explode( "\n", $wgRequest->getText( 'pages' ) );
			}
		}

		$category = $wgRequest->getVal( 'category' );
		$categoriesList = $category ? [$category] : false;
		if ( $categoriesList === false && $wgRequest->getCheck( 'categories' ) ) {
			$categoryBlob = $wgRequest->getText( 'categories' );

			if ( $categoryBlob !== '' ) {
				$categoriesList = explode( "\n", $categoryBlob );
			}
		}

		if ( $pages !== false ) {
			$this->exportPages( $pages );
			return;
		} else if ($categoriesList) {
			$this->exportPagesByCategories( $categoriesList );
			return;
		} else {
			$offset = $wgRequest->getVal( 'offset' );

			if ( isset( $offset ) ) {
				$this->startExport();
				$this->export_controller->printPageList( $offset );
				return;
			} else {
				$stats = $wgRequest->getVal( 'stats' );

				if ( isset( $stats ) ) {
					$this->startExport();
					$this->export_controller->printWikiInfo();
					return;
				}
			}
		}

		// Nothing exported yet; show user interface:
		$this->showForm();
	}

	/**
	 * Create the HTML user interface for this special page.
	 */
	protected function showForm() {
		global $wgOut, $wgUser, $smwgAllowRecursiveExport, $smwgExportBacklinks, $smwgExportAll;

		$html = '<form name="tripleSearch" action="" method="POST">' . "\n" .
					'<p>' . wfMessage( 'smw_exportrdf_docu' )->text() . "</p>\n" .
					'<input type="hidden" name="postform" value="1"/>' . "\n" .
					'<textarea name="pages" cols="40" rows="10"></textarea><br />' . "\n" .
					'<p>' . wfMessage( 'smw_semjsonexport_docu_categories' )->text() . "</p>\n" .
					'<textarea name="categories" cols="40" rows="3"></textarea><br />' . "\n";

		if ( $wgUser->isAllowed( 'delete' ) || $smwgExportAll ) {
			$html .= '<br />';
			$html .= '<input type="text" name="date" value="' . date( DATE_W3C, mktime( 0, 0, 0, 1, 1, 2000 ) ) . '" id="date">&#160;<label for="ea">' . wfMessage( 'smw_exportrdf_lastdate' )->text() . '</label></input><br />' . "\n";
		}

		$html .= '<br /><input type="submit"  value="' . wfMessage( 'smw_exportrdf_submit' )->text() . "\"/>\n</form>";

		$wgOut->addHTML( $html );
	}

	/**
	 * Prepare $wgOut for printing non-HTML data.
	 */
	protected function startExport() {
		global $wgOut, $wgRequest;

		$wgOut->disable();
		ob_start();

		$mimetype = 'application/json';
		$serializer = new SerializerJson();

		header( "Content-type: $mimetype; charset=UTF-8" );

		$this->export_controller = new ExportController( $serializer );

		$fieldsToParse = $wgRequest->getText( 'fieldsToParse' );
		if ( $fieldsToParse === '' ) {
			$fieldsToParse = $wgRequest->getVal( 'fieldsToParse' );
		}
		$fieldsToParse = explode(',', $fieldsToParse);

		$this->export_controller->setFieldsToParse($fieldsToParse);

		if ($wgRequest->getCheck( 'imagesInfo' ) ) {
            		$this->export_controller->setAddImagesInfo(true);
		}
	}

	/**
	 * Export all pages of given categories (limit of 100 pages by category).
	 * @param array $categories containing the string names of categories to be exported
	 */
	protected function exportPagesByCategories( $categories ) {
		global $wgServer, $wgScriptPath, $wgRequest;

		$pages = [];
		foreach ($categories as $catName) {
			$category = \Category::newFromName($catName);
			if($category) {
				$pagesTitles = $category->getMembers(100);
				foreach ($pagesTitles as $page) {
					$pages[] = $page;
				}
			}
		}

		$date = $wgRequest->getText( 'date' );
		if ( $date === '' ) {
			$date = $wgRequest->getVal( 'date' );
		}
		if ( $date !== '' ) {
			$timeint = strtotime( $date );
			$stamp = date( "YmdHis", $timeint );
			$date = $stamp;
		}

		$this->startExport();
		$this->export_controller->printPages( $pages, 1, $date );
	}

	/**
	 * Export the given pages to RDF.
	 * @param array $pages containing the string names of pages to be exported
	 */
	protected function exportPages( $pages ) {
		global $wgRequest, $smwgExportBacklinks, $wgUser, $smwgAllowRecursiveExport;

		// Effect: assume "no" from missing parameters generated by checkboxes.
		$postform = $wgRequest->getText( 'postform' ) == 1;

		$recursive = 1;  // default, no recursion
		$rec = $wgRequest->getText( 'recursive' );

		if ( $rec === '' ) {
			$rec = $wgRequest->getVal( 'recursive' );
		}

		if ( ( $rec == '1' ) && ( $smwgAllowRecursiveExport || $wgUser->isAllowed( 'delete' ) ) ) {
			$recursive = 1; // users may be allowed to switch it on
		}

		$backlinks = $smwgExportBacklinks; // default
		$bl = $wgRequest->getText( 'backlinks' );

		if ( $bl === '' ) {
			// TODO: wtf? this does not make a lot of sense...
			$bl = $wgRequest->getVal( 'backlinks' );
		}

		if ( ( $bl == '1' ) && ( $wgUser->isAllowed( 'delete' ) ) ) {
			$backlinks = true; // admins can always switch on backlinks
		} elseif ( ( $bl == '0' ) || ( '' == $bl && $postform ) ) {
			$backlinks = false; // everybody can explicitly switch off backlinks
		}

		$date = $wgRequest->getText( 'date' );
		if ( $date === '' ) {
			$date = $wgRequest->getVal( 'date' );
		}

		if ( $date !== '' ) {
			$timeint = strtotime( $date );
			$stamp = date( "YmdHis", $timeint );
			$date = $stamp;
		}

		// If it is a redirect then we don't want to generate triples other than
		// the redirect target information
		if ( isset( $pages[0] ) && Title::newFromText( $pages[0] )->isRedirect() ) {
			$backlinks = false;
		}

		$this->startExport();
		$this->export_controller->printPages( $pages, $recursive, $date );
	}

	protected function getGroupName() {
		return 'smw_group';
	}
}
