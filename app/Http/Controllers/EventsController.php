<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EventsController extends Controller
{
    protected $dir;
    protected $xml = 'events.xml';
    protected $xmlFullPath;
    protected $json = 'events.json';
    protected $jsonFullPath;

    /**
    * Create a new controller instance.
    *
    * @return void
    */
    public function __construct()
    {
        $s = DIRECTORY_SEPARATOR;

        $this->dir = storage_path('events');
        $this->xmlFullPath = $this->dir . $s . $this->xml;
        $this->jsonFullPath = $this->dir . $s . $this->json;
    }
    
    /**
    * Get the Events as JSON
    *
    * @return Response JSON
    */
    public function getEvents() {
        $json = file_get_contents($this->jsonFullPath);

        return response($json)->header('Content-Type', 'application/json');
    }

    /**
    * Check the PIN
    *
    * @param  Request  $request
    * @return Boolean
    */
    public function checkPin($request) {
        $pin = env('PIN_HASH', false);
        return $pin && md5($request->pin) === md5($pin);
    }

    /**
    * Upload CSV or XML File
    *
    * @param  Request  $request
    * @return Response
    */
    public function uploadFile(Request $request) {

        // Check the PIN
        if ( !$this->checkPin($request) ) {
            return response('wrong_pin', 401);
        }

        // if ($request->type(''))

        // Upload the file
        $request->file('file')->move($this->dir, $this->xml);

        // Generate Array / Json and put file contents
        $events = $this->generateEvents();

        // Check if Events-array is correct
        if(!$events) {
            return response('no_events', 401);
        } else if (count($events) <= 3) {
            return response('not_enought_events', 401);
        } else {
            file_put_contents($this->jsonFullPath, json_encode($events));
            return response('file_uploaded');
        }
    }
    
    /**
    * Generate an Array of the Events
    *
    * @return Array
    */
    public function generateEvents() {
        $events = $this->array_from_worksheet_table($this->xmlFullPath, 'events');
        $events = array_values($events); // Array to Value-Array
        array_shift($events); // Remove first row (only descriptions)
        return $events;
    }

    /**
    * Create Array from Worksheet Table
    *
    * @param  File  $file
    * @param  String  $worksheet_name
    * @return Array
    */
    private function array_from_worksheet_table($file, $worksheet_name) {
        
        // https://stackoverflow.com/questions/7082401/avoid-domdocument-xml-warnings-in-php
        $previous_errors = libxml_use_internal_errors(true);
        
        $dom = new \DOMDocument;
        if( !$dom->load($file) ) {
            foreach (libxml_get_errors() as $error) {
                // print_r($error);
            }
        }
        
        libxml_clear_errors();
        libxml_use_internal_errors($previous_errors);
        
        
        // returns a new instance of class DOMNodeList
        $worksheets = $dom->getElementsByTagName( 'Worksheet' );
        
        foreach($worksheets as $worksheet) {
            if( $worksheet->getAttribute('ss:Name') == $worksheet_name) {
                
                // When we get a DOMNodeList, if we want to access the first item, we have to
                // then use ->item(0). Important once we want to access a deeper-level DOMNodeList
                $rows = $worksheet->getElementsByTagName('Table')->item(0)->getElementsByTagName('Row');
                
                $table = array();
                
                // Get our headings.
                // This assumes that the first row HAS our headings!
                $headings = $rows->item(0)->getElementsByTagName('Cell');
                
                // loop through table rows. Setting $i=1 instead of 0 means we skip the first row
                for( $i = 1; $i < $rows->length; $i++ ) {
                    
                    // this is our row of data
                    $cells = $rows->item($i)->getElementsByTagName('Cell'); 
                    
                    // loop through each cell
                    for( $c = 0; $c < $cells->length; $c++ ) {
                        
                        // check for data element in cell
                        $celldata = $cells->item($c)->getElementsByTagName('Data');
                        
                        // If the cell has data, proceed
                        if( $celldata->length ) {
                            
                            // Get HTML content of any strings
                            if( $celldata->item(0)->getAttribute('ss:Type')== 'String' ) {
                                
                                // Does not work for PHP < 5.3.6
                                // If you HAVE PHP 5.3.6 then use function @ https://stackoverflow.com/questions/2087103/
                                // $value = xml_to_json::DOMinnerHTML( $celldata->item(0) );
                                
                                // DOMNode::C14N canonicalizes nodes into strings
                                // This workaround is required for PHP < 5.3.6
                                $value = $celldata->item(0)->C14N();
                                
                                // hack. remove tags like <ss:Data foo...> and </Data>
                                // Necessary because C14N leaves outer tags (saveHTML did not)
                                $value = preg_replace('/<([s\/:]+)?Data([^>]+)?>/i', '', $value);
                                
                                // Remove font tags from HTML. Bleah.
                                $value = preg_replace('/<\/?font([^>]+)?>/i', '', $value);
                            } else {
                                $value = $cells->item($c)->nodeValue;
                            }
                            
                            // grab label from first row
                            $label = $headings->item($c)->nodeValue;
                            
                            $table[$i][$label] = $value;
                        }
                    }
                }
                return $table;
            }
        }
        return false;
    }
}
