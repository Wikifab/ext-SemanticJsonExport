<?php
namespace Semanticjsonexport;

use DerivativeRequest;
use ApiMain;

/**
 * this parse a piece of wikitext into html format
 *
 *
 * @author Pierre Boutet
 */

class WikitextParser  {

	public static function parse($text, $title = null) {
		global $wgRequest;

		if (! $text || ! is_string($text)) {
			// do not do a parse for nothing !
			return $text;
		}

		$params = new DerivativeRequest(
				$wgRequest, // Fallback upon $wgRequest if you can't access context.
				array(
						'action' => 'parse',
						'text' => $text,
						'title' => $title
				)
		);

		$api = new ApiMain( $params );
		$api->execute();
		$data = $api->getResult()->getResultData();

		if(! isset($data['parse']['text'])) {
			// if we get not text or an error, return the original text
			return $text;
		}
		$text = $data['parse']['text'];

		// remove comments :
		$text = preg_replace('/<!--([^>]*)-->/', '', $text);
		return $text;
	}

}