<?php
    
    // <!-- https://html5.tutorials24x7.com/blog/how-to-capture-image-from-camera -->
    
    include_once '../login/serviceServer.php';

    // auth check
    $user = new authMaintainer();
    if (!($user->statusCheck())){
        header("Location: https://".join(DIRECTORY_SEPARATOR, array($config['SSOdomain'], $config['SSOrootPath'], $config['SSOerrPath'])));
        die();
    }


    // render the requested image
    if ((isset($_GET['act'])) &&($_GET['act'] == "stream")){ 

        if (!isset($_GET['f'])) {
            header("HTTP/1.0 404 Not Found");
            die();
        }

        header("Access-Control-Allow-Origin: *");

        $file = getcwd().'/'.$_GET['f'];

        $pos = strpos($file, 'php');
        if ($pos !== false) {
        header("HTTP/1.0 404 Not Found");die();}

        $pos = strpos($file, '..');
        if ($pos !== false) {
        header("HTTP/1.0 404 Not Found");die();}

        $file=preg_replace('/[^A-Za-z0-9.\]\[(\/)% _\-]/', '-', $file); 

        if (is_dir($file)) {
        header("HTTP/1.0 404 Not Found");die();}

        if (file_exists($file)) {

            $file_extension = strtolower(substr(strrchr($file,"."),1));
            $ctype="text/css";
            switch( $file_extension ) {
                case "gif": $ctype="image/gif"; break;
                case "png": $ctype="image/png"; break;
                case "jpeg":$ctype="image/jpeg"; break;
                case "jpg": $ctype="image/jpeg"; break;
                case "mp4": $ctype="video/mp4"; break;
                case "webm": $ctype="video/webm"; break;
                case "js": $ctype="application/javascript"; break;
                case "svg": $ctype="image/svg+xml"; break;
                default: //$ctype="invalid";
            }

            //if ($ctype=="invalid"){header("HTTP/1.0 404 Not Found");die();}

            header("Content-Type: ". $ctype);

            readfile($file);
        }
        else {
            header("HTTP/1.0 404 Not Found");
        }
    }

    // get the file name of the most up to date image
    if ((isset($_GET['act'])) && ($_GET['act'] == "view")){ 

        if ( (!(isset($_GET['f']))) || (!(is_numeric($_GET['f']))) ){ // if no current user file name provided
                $json = json_encode(array(
                    'name' => '',
                    'status' => 'Failed',
                ));
            
                echo $json;
                die();
        }
        
        $datapath = 'data/';
        $file_ext = ".jpg";
        $fileValidPeriod = 30*1000; // delete file after $fileValidPeriod seconds
        $requestTimeOut = 1500; // milliseconds, request wait for file to come up for this much time
        $loopSleepTime = 5; // milliseconds, wait before next round of research, not to overload server
        
        //$timestamp = round(microtime(true) * 1000);
        $millitimestamp = round(microtime(true) * 1000);
        
        
        while ((round(microtime(true) * 1000)-$millitimestamp)<$requestTimeOut){ // request wait for file to come up 
        
            $allfiles = array_diff(scandir($datapath), array('.', '..','.htaccess','.ipynb_checkpoints'));
            $streamFilename = '';
            
            $allfiles = array_map(function ($value) use($file_ext) { 
                $filetimestamp = (int) filter_var(basename($value, $file_ext), FILTER_SANITIZE_NUMBER_INT);
                if (is_numeric($filetimestamp)) 
                    return $filetimestamp;
                }, $allfiles);
            $allfiles = array_filter($allfiles);
            rsort($allfiles); // put the latest first
            foreach($allfiles as $value){
                # if is the latest and if not empty file
                if (($value>$millitimestamp-$fileValidPeriod) // if within valid period
                    &&($value>intval($_GET['f'])) // if file is more up-to-date than user's current one
                    &&(filesize($datapath.$value.$file_ext)>1)){ // file not empty (not currently being written to)
                    $streamFilename = $value.$file_ext;
                    break; // break from loop when find one file (in an already reverse sorted array)
                }
            }
            if ($streamFilename!=''){ // if at this moment, the newest file is found, then break while loop
                break;
            }
            usleep($loopSleepTime*1000);
        }

        if ($streamFilename==''){ // if no updated file found
            if ($millitimestamp-intval($_GET['f'])<$fileValidPeriod){ // if there is stream, but just not updated file
                $status = 'No update';
            }
            else { // no stream
                $status = 'Failed';
            }
        }
        else {
            $status = 'Success';
        }

        $json = json_encode(array(
              'name' => $datapath.$streamFilename,
              'status' => $status,
              'size' => filesize($datapath.$streamFilename),
        ));

        echo $json;
        die();
    }


?>

<html lang="en"><head><meta charset="utf-8" /><meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1"><title>View Streaming - Yuxin Pan</title><link rel="shortcut icon" href="https://www.panyuxin.com/favicon.ico"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, minimum-scale=1.0">

    
<script src="https://www.panyuxin.com/assets/js/jquery.min.js"></script>
    
<script src="assets/custom.js"></script>
<link rel="stylesheet" href="assets/custom.css">
    
<style>
    h1, h2, h3, h4, h5, h6 {
        padding:0;
        margin:0;
    }

    .button-group, .play-area {
      border: 1px solid grey;
      padding: 0.5em 0.5%;
      margin-bottom: 1em;
    }

    .button {
      padding: 0.5em;
      margin-right: 1em;
    }

    .play-area-sub {
      /*width: 47%;*/
      padding: 0.5em 0.5%;
      display: inline-block;
      /*text-align: center;*/
    }

    #capture {
      display: none;
    }

    #snapshot {
      display: inline-block;
      /*width: 300px;
      height: 300px;*/
    }

</style>

<body>
        
<!-- The buttons to control the stream -->
<div class="button-group">
  <button id="btn-start" type="button" class="btn btn-primary">Start View</button>
  <button id="btn-stop" type="button" class="btn btn-primary">Stop View</button>
  <button id="btn-streaming" type="button" class="btn btn-primary">Streaming Page</button>
</div>
    
<!-- Video Element & Canvas -->
<div class="play-area">
  <div class="play-area-sub">
    <h3>View Stream</h3>
    <!-- size setting here has no effect, depending on image size -->
    <!-- <canvas id="capture" width="480" height="360"></canvas> -->
    <div id="snapshot">
        <img id="pic" src="">
    </div>
    <br>
    <div id="timestampIndicator"></div>
    <br>
    <!-- <div id="metricFrameRate"></div>-->
    <div>
        <table style="border:0px;margin-left:auto;margin-right:auto;">
          <tr>
            <td>Frame Rate: </td>
            <td id="metricFrameRate">0.0</td>
            <td> FPS</td>
          </tr>
          <tr>
            <td>Transfer Rate: </td>
            <td id="metricBitRate">0.0</td>
            <td> kB/s</td>
          </tr>
        </table>
    </div>
  </div>
</div>
    

<script>
    
const requestInterval = 5000; // polling rate when no streaming available
const xhrTimeout = 6000; // millisecond
const logLength = 6;
const emptyTransferSize = 9; // kB, the extra http image request size (transfer size on top of image size)
const detectedBrowser = fnBrowserDetect();
const borderWidth = 40; // border between image feed and window edge
const capture_width = 480;
const capture_height = 360;
var display_width, display_height;

// console.log(detectedBrowser);

var currentFileTimestamp = 0;
var logTimestamp = []; // log of timestamp for frame rate analysis with the array length of logLength
var logFileSize = []; // log of image file size for bit rate analysis with the array length of logLength

var btnStart = document.getElementById( "btn-start" );
var btnStop = document.getElementById( "btn-stop" );
var btnStreaming = document.getElementById( "btn-streaming" );



// Attach listeners
btnStart.addEventListener( "click", startStream );
btnStop.addEventListener( "click", stopStreaming );
btnStreaming.addEventListener( "click", redirectStreamingPage );

document.getElementById("btn-stop").style.display = "none"; // hide stop button



// Detect user browser type
function fnBrowserDetect() {
                 
    let userAgent = navigator.userAgent;
    let browserName;

    if(userAgent.match(/chrome|chromium|crios/i)){
        browserName = "chrome";
    }else if(userAgent.match(/firefox|fxios/i)){
        browserName = "firefox";
    }  else if(userAgent.match(/safari/i)){
        browserName = "safari";
    }else if(userAgent.match(/opr\//i)){
        browserName = "opera";
    } else if(userAgent.match(/edg/i)){
        browserName = "edge";
    }else{
        browserName="No match";
    }

    return browserName;

}


// Start Streaming
function startStream() {

    // show stop button, hide stop button
    document.getElementById("btn-start").style.display = "none";
    document.getElementById("btn-stop").style.display = "inline";

    // set a predefined image snapshot size
    let snapshot_pic = document.getElementById('pic');
    if (window.innerWidth<capture_width+borderWidth) {
        display_width = window.innerWidth-borderWidth;
        display_height = (window.innerWidth-borderWidth)/capture_width*capture_height;
        snapshot_pic.width = display_width;
        snapshot_pic.height = display_height;
    }
    else {
        display_width = capture_width;
        display_height = capture_height;
        snapshot_pic.width = display_width;
        snapshot_pic.height = display_height;
    }

    // start multiple function "threads" in polling newest image 
    setTimeout("viewStream()",0);
    setTimeout("viewStream()",500);
    setTimeout("viewStream()",1000);
    // setTimeout("viewStream()",1500);

}


// Stop streaming by redirecting to the same page
function stopStreaming() {

    window.location.replace("./view.php");

}


// Redirect to video streaming host page
function redirectStreamingPage() {

    window.location.replace("./");

}


// format time string
function timeBreakout(inputTime) {

    let dateObj = new Date(inputTime);

    let months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let year = dateObj.getFullYear();
    let month = months[dateObj.getMonth()];
    let date = dateObj.getDate();
    let hour = dateObj.getHours();
    let min = dateObj.getMinutes();
    let sec = dateObj.getSeconds();
    let formattedTime = year + '-' + month + '-' + date + '  ' + hour + ':' + min + ':' + sec ;

    return formattedTime;
    
}


// load an image in background before displaying
function preloadImage(img, resp, anImageLoadedCallback){ // download image from server before displaying

    // fix for Firefox flickering issue:
    // https://stackoverflow.com/questions/14704796/image-reload-causes-flicker-only-in-firefox
    img.onload = anImageLoadedCallback;
    // set the source of the new image to trigger the load 
    img.src = 'view.php?act=stream&f='+resp.name;

}


// view stream
function viewStream() {

    let request = new XMLHttpRequest();
    request.open( "GET", "view.php?act=view&f="+String(currentFileTimestamp), async=true );
    request.timeout = xhrTimeout; // time in milliseconds
    request.send();
    
    request.onload = function() {
        if (request.status != 200) {
            // analyze HTTP status of the response
            setTimeout("viewStream()", 0);
        }
        else {

            let resp = JSON.parse(request.response);
            //var img = new Image();
            let dispTime= resp.name.substring(resp.name.indexOf('/')+1,resp.name.indexOf('.'));
            let respTimestamp = parseInt(dispTime, 10);


            if (resp.status=='Success'){

                if (respTimestamp<=currentFileTimestamp){ // if this file timestamp has already been returned
                    setTimeout("viewStream()", 0);
                }
                else {

                    currentFileTimestamp = respTimestamp; // going to load this image, update timestamp

                    var img = new Image();
                    preloadImage(img, resp, function () { // don't write this as a separate callback function

                        // this is required due to firefox image flickering issue
                        if (detectedBrowser=="firefox") { // this will be slow for Chrome but not Firefox
                            document.images["pic"].src = img.src; // replace the existing image once the new image has loaded
                        }
                        else { // this method does not introduce slow down for Chrome
                            snapshot.innerHTML = '';
                            img.style.width = display_width; // need to resize image again since it was cleared
                            img.style.height = display_height;
                            snapshot.appendChild(img); // display image
                        }
                        timestampIndicator.innerHTML = timeBreakout(respTimestamp);

                        logTimestamp.push(respTimestamp);

                        //console.log(logTimestamp);
                        if (logTimestamp.length > logLength) {
                            logTimestamp.shift(); // Remove an item from the beginning of an array
                        }
                        let logTimeSpan = (logTimestamp[logTimestamp.length - 1] - logTimestamp[0]) / 1000;
                        if (logTimeSpan>0) {
                            metricFrameRate.innerHTML = (logTimestamp.length / logTimeSpan).toFixed(2);
                        }

                        logFileSize.push(resp.size/1000+emptyTransferSize);
                        if (logFileSize.length>logLength){
                            logFileSize.shift(); // Remove an item from the beginning of an array
                        }
                        let sum = 0;
                        logTimeSpan = (logTimestamp[logTimestamp.length - 1] - logTimestamp[0]) / 1000;

                        for( let i = 0; i < logFileSize.length; i++ ){
                            sum += parseInt( logFileSize[i], 10 ); //don't forget to add the base
                        }
                        if (logTimeSpan>0) {
                            metricBitRate.innerHTML = (sum / logTimeSpan).toFixed(2);
                        }
                        setTimeout("viewStream()", 0);

                    });
                }
            }
            else if (resp.status=='No update'){
                setTimeout("viewStream()", 0);
            }
            else {
                snapshot.innerHTML = 'No Streaming available.';
                setTimeout("viewStream()", requestInterval); // reduce polling rate when no streaming available
            }
            
        }
    };
    
    request.ontimeout = function () {
        // XMLHttpRequest timed out.
        setTimeout("viewStream()", 0);
    };

}

</script>

</body>
</html>
