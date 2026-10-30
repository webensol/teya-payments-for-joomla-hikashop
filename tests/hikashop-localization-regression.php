<?php
$root=dirname(__DIR__).'/plugins/hikashoppayment/teya';
$english=null;
foreach (array('en-GB','hu-HU','cs-CZ','sk-SK','de-DE') as $locale) {
 $file=$root.'/language/'.$locale.'/'.$locale.'.plg_hikashoppayment_teya.ini';
 $lines=file($file,FILE_IGNORE_NEW_LINES); $keys=array();
 foreach ($lines as $line) { if (preg_match('/^([A-Z0-9_]+)=/',$line,$match)) { if (isset($keys[$match[1]])) throw new RuntimeException('Duplicate language key: '.$locale); $keys[$match[1]]=true; } }
 $strings=parse_ini_file($file,false,INI_SCANNER_RAW);
 if ($english===null) $english=$strings;
 if (array_diff_key($strings,$english) || array_diff_key($english,$strings)) throw new RuntimeException('Language keys differ: '.$locale);
 foreach ($strings as $key=>$message) {
  if ($message==='') throw new RuntimeException('Empty translation: '.$locale.' '.$key);
  preg_match_all('/%[0-9]*\$?[sd]/',$message,$found);
  preg_match_all('/%[0-9]*\$?[sd]/',$english[$key],$expected);
  if (count($found[0])!==count($expected[0])) throw new RuntimeException('Translation placeholders differ: '.$locale.' '.$key);
 }
}
echo 'PASS: matching language keys, nonempty translations, no duplicates and matching placeholders in five languages'.PHP_EOL;
