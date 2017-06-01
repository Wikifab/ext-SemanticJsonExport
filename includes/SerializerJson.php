<?php
namespace Semanticjsonexport;

use SMW\SemanticData;
use Title;
use SMWDataItem;

/**
 * File holding the SMWRDFXMLSerializer class that provides basic functions for
 * serialising OWL data in RDF/XML syntax.
 *
 *
 * @author Pierre Boutet
 */

class SerializerJson  {


	/**
	 * @var Array
	 */
	protected $pages = [];

	/**
	 * @var string
	 */
	protected $buffer = '';

	/**
	 * @var boolean
	 */
	protected $bufferContainPages = false;


	/**
	 * @var boolean
	 */
	protected $isFinish;

	public function clear() {
	}

	public function startSerialization() {
		$this->isFinish = false;

		$this->buffer = '{"results":[';
		$this->bufferContainPages = false;
	}

	public function finishSerialization() {

		$this->buffer .= ']}';
		$this->isFinish = true;
	}

	protected function serializeHeader() {
	}

	protected function serializeFooter() {
	}

	protected function addPageInBuffer($page) {
		if($this->bufferContainPages) {
			$this->buffer .= ',';
		}
		$this->buffer .= json_encode($page);
		$this->bufferContainPages  = true;
	}

	public function addPage(Title $title, $pageInfo, $additionalData) {
		$page = [];

		$page['namespace'] = $title->getNamespaceKey();
		$page['id'] = $title->getDBkey();
		$page['title'] = $title->getText();

		$page = array_merge($page,$pageInfo);

		$page['content'] = $additionalData;
		$this->pages[] = $page;

		$this->addPageInBuffer($page);
	}

	/**
	 * convert list of SMWPropertyValue to an array ready for json encode
	 * @param array<SMWPropertyValue> $properties
	 */
	protected function propertiesToArray( $semData) {
		$result = [];
		foreach ($semData->getProperties() as $property) {
			$values = [];
			foreach ($semData->getPropertyValues($property) as $value) {
				$values[] = $this->getValue($value);
			}
			if(count($values) == 1) {
				$values = array_pop($values);
			}
			$result[$property->getKey()] = $values;
		}
		return $result;
	}

	/**
	 * this return a value (string, number, or array) ready to be json Encode
	 *
	 * @param SMWDataItem $item
	 * @return array|string
	 */
	protected function getValue(SMWDataItem $item) {
		switch( $item->getDIType()) {
			case SMWDataItem::TYPE_BLOB :
			case SMWDataItem::TYPE_STRING :
				return $item->getString();
			case SMWDataItem::TYPE_BOOLEAN :
				return $item->getBoolean();
			case SMWDataItem::TYPE_CONTAINER :
				return $this->propertiesToArray($item);
			case SMWDataItem::TYPE_NUMBER :
				return $item->getNumber();
			case SMWDataItem::TYPE_WIKIPAGE :
				return $item->getTitle() ? $item->getTitle()->getFullText() : '';
			case SMWDataItem::TYPE_TIME :
				return '';
			case SMWDataItem::TYPE_CONCEPT :
			case SMWDataItem::TYPE_ERROR :
			case SMWDataItem::TYPE_GEO :
			case SMWDataItem::TYPE_NOTYPE :
			case SMWDataItem::TYPE_PROPERTY :
			case SMWDataItem::TYPE_URI :
				return $item->getSerialization();
			default :
				return '';
		}
	}

	public function flushContent() {

		$result = $this->buffer;

		$this->pages = [];
		$this->buffer = '';

		return $result;
	}

}
