<?php

/*    This file is part of DynamicEmulationATSCDASH.

    DynamicEmulationATSCDASH is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    DynamicEmulationATSCDASH is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with DynamicEmulationATSCDASH.  If not, see <http://www.gnu.org/licenses/>.
	*/
	
$micro_date = microtime();
$date_array = explode(" ",$micro_date);
$date = date("Y-m-d H:i:s",$date_array[1]);
#unlink ('../bin/timelog.txt');
#file_put_contents ( "timelog.txt" , "Start:" . $date . $date_array[0] . " \r\n" , FILE_APPEND );

ini_set('memory_limit','-1');//remove memory limit

/* 
Main script for starting flure reception and MPD re-writing
 */
$AdSource = "0";
#chdir('../bin/');
$currDir=dirname(__FILE__);

$channel = '1';
$responseToSend = array();
$responseToSend[0] = $channel;
//echo "Started channel ". $channel;

#Define Paths

$DASHContent="../../Work/Route_Sender/bin/ToS_1_0/";
$OriginalMPD= "MultiRate_Dynamic.mpd";
$AdMPDName="Ad2/Ad2_MultiRate.mpd";


$Delay=0;	#How much would the AST of the patched MPD be lagging the current system time, i.e. how far in future is the AST (in seconds)?
$PatchedMPD="MultiRate_Dynamic_Patched.mpd";
#HTMLLocalStorage="/home/nomor/.config/google-chrome-unstable/Default/Local Storage/"

if (isset($_GET['astoffset'])) {
$offsetToNow=$_GET['astoffset'];
}
else
$offsetToNow = 0;

$micro_date = microtime();
$date_array = explode(" ",$micro_date);
$date_array[0] = round($date_array[0],4);
$date = date("Y-m-d H:i:s",$date_array[1]);
//file_put_contents ( "timelog.txt" , "Tuned in:" . $date . $date_array[0] . " \r\n" , FILE_APPEND );

$AST_SEC = new DateTime( 'now',  new DateTimeZone( 'UTC' ) );	/* initializer for availability start time */
$AST_SEC->setTimestamp($date_array[1]);    //Better use a single time than now above
//$AST_SEC->add(new DateInterval('PT1S'));
$AST_SEC_W3C = $AST_SEC->format(DATE_W3C);

preg_match('/\.\d*/',$date_array[0],$dateFracPart);
$extension_pos = strrpos($AST_SEC_W3C, '+'); // find position of the last + in W3C date to slip frac seconds
$AST_W3C = substr($AST_SEC_W3C, 0, $extension_pos) . $dateFracPart[0] . "Z" ;//substr($AST_SEC_W3C, $extension_pos);
//file_put_contents ( "timelog.txt" , "Setting AST: " . $AST_W3C . " \r\n" , FILE_APPEND );

$ASTO_SEC = new DateTime( 'now',  new DateTimeZone( 'UTC' ) );	/* initializer for availability start time */
$ASTO_SEC->setTimestamp($date_array[1] - $offsetToNow);    //Better use a single time than now above
$ASTO_SEC_W3C = $ASTO_SEC->format(DATE_W3C);

$extension_pos = strrpos($ASTO_SEC_W3C, '+'); // find position of the last + in W3C date to slip frac seconds
$ASTO_W3C = substr($ASTO_SEC_W3C, 0, $extension_pos) . $dateFracPart[0] . "Z" ;//substr($AST_SEC_W3C, $extension_pos);

    $MPD = simplexml_load_file($DASHContent . "/" . $OriginalMPD);
    if (!$MPD)
	    die("Failed loading XML file");

    $dom_sxe = dom_import_simplexml($MPD);
    if (!$dom_sxe) 
    {
        echo 'Error while converting XML';
        exit;
    }
    
    $dom = new DOMDocument('1.0');
    $dom_sxe = $dom->importNode($dom_sxe, true);
    $dom_sxe = $dom->appendChild($dom_sxe);
    
    $periods = parseMPD($dom->documentElement);
    

    $cumulativeUpdatedDuration = 0;    //Cumulation of period duration on updated MPD
    $tuneInPeriodStart = 0;
    
    $MPDNode = &$periods[0]['node']->parentNode;
    
    $MPD_AST = $MPDNode->getAttribute("availabilityStartTime");
    $MPD_AST = $ASTO_W3C;//$MPDNode->getAttribute("availabilityStartTime");
    preg_match('/\.\d*/',$MPD_AST,$matches);
    $fracAST = "0" . $matches[0];
    $originalAST = new DateTime($MPD_AST);   
    $deltaTimeASTTuneIn = $AST_SEC->getTimestamp() + round($date_array[0],4) - ($originalAST->getTimestamp() + $fracAST);  //Time elapsed between the original AST and Tune-in time
    
    //file_put_contents ( "timelog.txt" , "TimeOffset: " . $deltaTimeASTTuneIn . ", Original ts:" . ($originalAST->getTimestamp() + $fracAST) . "Tune-in TS: " . ($AST_SEC->getTimestamp() + round($date_array[0],4)) . "\r\n" , FILE_APPEND );
    
    $MPDNode->setAttribute("availabilityStartTime",$AST_W3C);    //Set AST to tune-in time
    
    $periodStart;   //Start of this period in the iteration
    $duration;      //Duration of current period in the iteration
    $lastPeriodStart;   //Period start of the last period in the iteration
    $lastPeriodDuration;    //Period duration of the last period in iteration
	
	$responseToSend[1] = count($periods) - 1;
	
    for ($periodIndex = 0; $periodIndex < count($periods); $periodIndex++)  //Loop on all periods in orginal MPD
    {
        $periodStart = $periods[$periodIndex]['node']->getAttribute("start");
        $duration = somehowPleaseGetDurationInFractionalSecondsBecuasePHPHasABug($periods[$periodIndex]['node']->getAttribute("duration"));
        
        if($periodStart === '')
            $periodStart = $lastPeriodStart + $lastPeriodDuration;
        else
            $periodStart = somehowPleaseGetDurationInFractionalSecondsBecuasePHPHasABug($periodStart);	//Convert Duration string to number
 
        if($deltaTimeASTTuneIn < $periodStart)   //Tune-in is before this period, it stays intact (except that its start may need an update, which is optional for subsequent periods)
        {
            $periods[$periodIndex]['node']->setAttribute("start","PT". round($lastPeriodStart + $lastPeriodDuration,4)."S"); 
			//Set already for the next iteration
			$lastPeriodStart = $lastPeriodStart + $lastPeriodDuration;
			$responseToSend[] = $lastPeriodStart;
			$lastPeriodDuration = $duration;  
            continue;
        }
		
        //Set already for the next iteration
        $lastPeriodStart = $periodStart;
        $lastPeriodDuration = $duration;  
		
        if($deltaTimeASTTuneIn > $periodStart + $duration)   //This period is no more relevant and is not received, hence remove this
        {
            $dom->documentElement->removeChild ($periods[$periodIndex]['node']);
			$responseToSend[1] = $responseToSend[1] - 1;
            continue;
        }
        
        //The only case here is the period in which we tune in
        
        $videoSegmentTemplate = &$periods[$periodIndex]['adaptationSet'][0]['representation'][0]['segmentTemplate']['node'];
        $audioSegmentTemplate = &$periods[$periodIndex]['adaptationSet'][1]['representation'][0]['segmentTemplate']['node'];
        
        $videoTimescale = $videoSegmentTemplate->getAttribute("timescale");
        $videoSegmentDuration = $videoSegmentTemplate->getAttribute("duration");
        $videoStartNum = $videoSegmentTemplate->getAttribute("startNumber");
        $videoPTO = $videoSegmentTemplate->getAttribute("presentationTimeOffset");
        
        $newVideoStartNumber = ceil(($deltaTimeASTTuneIn - $periodStart)*$videoTimescale/$videoSegmentDuration) + $videoStartNum;
        //file_put_contents ( "timelog.txt" , "new video offset: " . ($deltaTimeASTTuneIn - $periodStart)*$videoTimescale/$videoSegmentDuration . "\r\n" , FILE_APPEND );
        $videoOffsetUpdate = ($newVideoStartNumber - $videoStartNum) * $videoSegmentDuration/$videoTimescale;
        
        $audioTimescale = $audioSegmentTemplate->getAttribute("timescale");
        $audioSegmentDuration = $audioSegmentTemplate->getAttribute("duration");
        $audioStartNum = $audioSegmentTemplate->getAttribute("startNumber");
        $audioPTO = $audioSegmentTemplate->getAttribute("presentationTimeOffset");
        
        $newAudioStartNumber = ceil(($deltaTimeASTTuneIn - $periodStart)*$audioTimescale/$audioSegmentDuration) + $audioStartNum;
        //file_put_contents ( "timelog.txt" , "new audio offset: " . ($deltaTimeASTTuneIn - $periodStart)*$audioTimescale/$audioSegmentDuration . "\r\n" , FILE_APPEND );
        $audioOffsetUpdate = ($newAudioStartNumber - $audioStartNum) * $audioSegmentDuration/$audioTimescale;
        
        // Find the smaller update offset of audio and video, set the other to the smaller
        $offsetUpdate = min($videoOffsetUpdate , $audioOffsetUpdate);
        
        $newAudioPTO = round($offsetUpdate*$audioTimescale + $audioPTO); //Round, since PTO is int type
        $newVideoPTO = round($offsetUpdate*$videoTimescale + $videoPTO); //Round, since PTO is int type
        
        //The adjusted period start and duration governed by new audio/video offset above.
        $periods[$periodIndex]['node']->setAttribute("start","PT". round($offsetUpdate + $periodStart - $deltaTimeASTTuneIn ,4)."S");         
        
        $remainingPeriodDuration = $duration - max($videoOffsetUpdate , $audioOffsetUpdate);
        
        $periods[$periodIndex]['node']->setAttribute("duration", "PT". round($remainingPeriodDuration,4) . "S");
        
        //Update again the last saved values for the next iteration
        $lastPeriodStart = $offsetUpdate + $periodStart - $deltaTimeASTTuneIn;
        $lastPeriodDuration = $remainingPeriodDuration;   
		
        $videoSegmentTemplate->setAttribute("presentationTimeOffset",$newVideoPTO);
        $videoSegmentTemplate->setAttribute("startNumber",$newVideoStartNumber);

        $audioSegmentTemplate->setAttribute("presentationTimeOffset",$newAudioPTO);
        $audioSegmentTemplate->setAttribute("startNumber",$newAudioStartNumber);
        
        //$periods[$periodIndex]['node']->removeChild ($periods[$periodIndex]['adaptationSet'][1]['node']);
    }
    
    //Set the updated MPD duration
    
    $MPDNode->setAttribute("mediaPresentationDuration","PT". round($lastPeriodStart + $lastPeriodDuration ,4) . "S");
	
	/*if($AdSource == 1 && count($periods) >= 2)
	{
		$BaseURL = $dom->createElement( "BaseURL", "../Ad2/");
		$TargetPeriod = $periods[count($periods) - 2];
		$TargetAS = $TargetPeriod['adaptationSet'][0]['node'];
		$TargetPeriodNode = $TargetPeriod['node'];
		$TargetPeriodNode->insertBefore($BaseURL,$TargetAS);
	}*/

	$BaseURL = $dom->createElement( "BaseURL", $DASHContent);
	$TargetPeriodNode = $periods[0]['node'];
	$MPDNode->insertBefore($BaseURL,$TargetPeriodNode);
		
    $dom->save($PatchedMPD);
    
    corsHeader();
    header("Content-Type: application/xml");
    $toecho = $dom->saveXML();

    echo $toecho;
	
	#file_put_contents ( "timelog.txt" , $latestFiles , FILE_APPEND );
	$micro_date = microtime();
	$date_array = explode(" ",$micro_date);
	$date = date("Y-m-d H:i:s",$date_array[1]);
	//file_put_contents ( "timelog.txt" , "Done:" . $date . $date_array[0] . " \r\n" , FILE_APPEND );

	exit;
	
/*Send out CORS header*/
function corsHeader()
{
	header("Access-Control-Allow-Headers: origin,range");
	header("Access-Control-Expose-Headers: Server,range");
	header("Access-Control-Allow-Methods: GET, HEAD, OPTIONS");
	header("Access-Control-Allow-Origin: *");
}

function &parseMPD($docElement)
{
    foreach ($docElement->childNodes as $node)
    {
        //echo $node->nodeName; // body
        if($node->nodeName === 'Location')
            $locationNode = $node;
        if($node->nodeName === 'BaseURL')
            $baseURLNode = $node;    
        if($node->nodeName === 'Period')
        {
            $periods[]['node'] = $node;

            $currentPeriod = &$periods[count($periods) - 1];
            foreach ($currentPeriod['node']->childNodes as $node)
            {
                if($node->nodeName === 'AdaptationSet')
                {
                    $currentPeriod['adaptationSet'][]['node'] = $node;
                    
                    $currentAdaptationSet = &$currentPeriod['adaptationSet'][count($currentPeriod['adaptationSet']) - 1];                    
                    foreach ($currentAdaptationSet['node']->childNodes as $node)
                    {
                        if($node->nodeName === 'Representation')
                        {
                            $currentAdaptationSet['representation'][]['node'] = $node;
                            
                            $currentRepresentation = &$currentAdaptationSet['representation'][count($currentAdaptationSet['representation']) - 1];

                            foreach ($currentRepresentation['node']->childNodes as $node)
                            {
                                if($node->nodeName === 'SegmentTemplate')
                                    $currentRepresentation['segmentTemplate']['node'] = $node;
                            }
                        }
                    }
                }
            }            
        }
    }
    
    return $periods;
}

function somehowPleaseGetDurationInFractionalSecondsBecuasePHPHasABug($durstr)
{
	    if(strpos($durstr,'.') !== FALSE)	//If indeed there is float values
		{
                        $temp = explode('.', $durstr);
			$durstrint = $temp[0] . 'S';
                        $temp1 = explode('.', $durstr);
                        $temp2 = explode('S',$temp1[1]);
			$fracSec = '0.' . $temp2[0];
		}
		else
		{
			$durstrint = $durstr;
			$fracSec = 0;
		}
		
        $di = new DateInterval($durstrint);

        $durationDT = new DateTime();
        $reft = clone $durationDT;
        $durationDT->add($di);
        $duration = $durationDT->getTimestamp() - $reft->getTimestamp() + $fracSec;
        
        return $duration;
}
?>