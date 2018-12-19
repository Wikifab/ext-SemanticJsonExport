
# Semantic Json Export

SemanticJsonExport is a mediawiki extension to allow export semantic data of pages

## installation :

place extension in the 'extensions' directory of your mediawiki, in a folder Called "SemanticJsonExport" and add this to your localsettings.php file :

  wfLoadExtension('SemanticJsonExport');
  

## use :

got go tpage Spécial:ExportSemanticJson and enter the pages you want to export


## hooks

this extension add a hook : 

'SemanticJsonExportBeforeSerializePage' [ Title $title, &$data ]