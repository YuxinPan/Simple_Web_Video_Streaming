<?php
    
    // https://zocada.com/compress-resize-images-javascript-browser/
    // https://developer.mozilla.org/en-US/docs/Web/API/WebRTC_API/Taking_still_photos
    
    
    include_once '../login/serviceServer.php';

    // auth check
    $user = new authMaintainer();
    if (!($user->statusCheck())){
        $query = array( // no need to configure this for new SSO service server
            'service' => $_SERVER['HTTP_HOST']
        );
        header("Location: https://".join(DIRECTORY_SEPARATOR, array($config['SSOdomain'], $config['SSOrootPath']))."?".http_build_query($query));        
        die();
    }


    // if the request is an API call to upload image
    if ( (isset($_GET['act']))&&($_GET['act'] == "upload") ){

        $datapath = 'data/';
        $fileValidPeriod = 30*1000; // delete file after $fileValidPeriod milliseconds

        $fileName = $_FILES['image']['name'];
        $fileType = $_FILES['image']['type'];
        $fileContent = file_get_contents($_FILES['image']['tmp_name']);
        $dataUrl = 'data:' . $fileType ;
        $millitimestamp = round(microtime(true) * 1000);

        // delete file if outdated
        $allfiles = array_diff(scandir($datapath), array('.', '..','.htaccess','.ipynb_checkpoints'));
        foreach($allfiles as $value){
            $filetimestamp = (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT); // get integer from filename
            if ((is_numeric($filetimestamp)) // if not empty
                &&($filetimestamp<$millitimestamp-$fileValidPeriod)){ // if outdated
                unlink($datapath.$value);
            }
        }

        $f = file_put_contents($datapath.$millitimestamp.'.jpg',$fileContent); // remember to check folder permission

        $json = json_encode(array(
              'file_type' => $dataUrl,
              'name' => $fileName,
              'time' => $millitimestamp,
              'receiving_status' => $f,
        ));

        echo $json;
        
//         if (strpos(strval(shell_exec('ps -A')),'inference-sub') === false) {
//             $full_output = shell_exec('nohup python3 /var/www/panyuxin.com/yolo/yolo.py > /dev/null 2>&1 &');
//         }

        die();
    }
?>

<html lang="en"><head><meta charset="utf-8" /><meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1"><title>Streaming - Yuxin Pan</title><link rel="shortcut icon" href="https://www.panyuxin.com/favicon.ico"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, minimum-scale=1.0">

    
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
      padding: 0.01em 0.01%;
      display: inline-block;
      /*text-align: center;*/
    }

    #capture {
      display: none;
    }

    #snapshot {
      display: none;
      /*width: 300px;
      height: 300px;*/
    }
</style>
<style>  /* range slider */
    .slidecontainer {
        width: 100%;
    }

    .slider {
        -webkit-appearance: none;
        width: 100%;
        height: 15px;
        border-radius: 5px;
        background: #d3d3d3;
        outline: none;
        opacity: 0.7;
        -webkit-transition: .2s;
        transition: opacity .2s;
    }

    .slider:hover {
        opacity: 1;
    }

    .slider::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        width: 25px;
        height: 25px;
        border-radius: 50%;
        background: #4CAF50;
        cursor: pointer;
    }

    .slider::-moz-range-thumb {
        width: 25px;
        height: 25px;
        border-radius: 50%;
        background: #4CAF50;
        cursor: pointer;
    }
</style>
<body>


<!-- The buttons to control the stream -->
<div class="button-group">
  <button id="btn-start" type="button" class="btn btn-primary">Start Camera</button>
  <button id="btn-stop" type="button" class="btn btn-primary">Stop Camera</button>
  <button id="btn-capture" type="button" class="btn btn-primary">Start Streaming</button>
  <button id="btn-view" type="button" class="btn btn-primary">View Stream</button>
</div>


<!-- Video threads selection slider -->
<div class="slidecontainer">
    <input type="range" min="1" max="10" value="2" class="slider" id="sliderRange">
    <p>Streaming Threads: <span id="sliderValue"></span></p>
</div>
<!-- Video threads selection slider -->
<div class="slidecontainer">
    <input type="range" min="1" max="100" value="85" class="slider" id="sliderRange2">
    <p>Streaming Quality (%): <span id="sliderValue2"></span></p>
</div>

<!-- Video Element & Canvas -->
<div class="play-area">
  <div class="play-area-sub">
    <h3>Camera Stream</h3>
    <video id="stream" width="320" height="320"></video>
  </div>
    <br>
  <div class="play-area-sub">
    <canvas id="capture" width="320" height="320"></canvas>
    <div id="timestampIndicator"></div>
    <div id="snapshot"></div>
    
    <center>
        <table>
          <tr>
            <td>Frame Rate: </td>
            <td id="metricFrameRate">0</td>
            <td> FPS</td>
          </tr>
          <tr>
            <td>Transfer Rate: </td>
            <td id="metricBitRate">0</td>
            <td> KB/s</td>
          </tr>
        </table>
    </center>
                
  </div>
</div>


    
<script>
    

var imageQuality = 0.85;                // stream image compression quality
var xhrTimeout = 5000;                  // millisecond
var logLength = 5;                      // log for streaming metrics
var streamThreadNum = 2;                // how many capture and upload threads
var threadSeparationNumerator = 1000;   // millisecond, between invocation of each thread

var logTimestamp = [];                  // log of timestamp for frame rate analysis with the array length of logLength
var logFileSize = [];                   // log of image file size for bit rate analysis with the array length of logLength


// The buttons to start & stop stream and to capture the image
var btnStart = document.getElementById( "btn-start" );
var btnStop = document.getElementById( "btn-stop" );
var btnCapture = document.getElementById( "btn-capture" );
var btnView = document.getElementById( "btn-view" );

    
// The stream & capture
var stream = document.getElementById( "stream" );
var capture = document.getElementById( "capture" );
var snapshot = document.getElementById( "snapshot" );


// slider for thread selection
var slider = document.getElementById("sliderRange");
var output = document.getElementById("sliderValue");
output.innerHTML = slider.value;

slider.oninput = function() {
    output.innerHTML = this.value;
    streamThreadNum = this.value;
}

// slider for streaming image quality
var slider2 = document.getElementById("sliderRange2");
var output2 = document.getElementById("sliderValue2");
output2.innerHTML = slider2.value;

slider2.oninput = function() {
    output2.innerHTML = this.value;
    imageQuality = this.value/100;
}

 
// The video stream
var cameraStream = null;

// Attach listeners
btnStart.addEventListener( "click", startStreaming );
btnStop.addEventListener( "click", stopStreaming );
btnCapture.addEventListener( "click", startUploading );
btnView.addEventListener( "click", viewStream );

// no stop or stream option when camera not started at first
document.getElementById("btn-stop").style.display = "none";
document.getElementById("btn-capture").style.display = "none";


// Start local camera, not uploading yet
function startStreaming() {

    document.getElementById("btn-stop").style.display = "inline";
    document.getElementById("btn-capture").style.display = "inline";
    document.getElementById("btn-start").style.display = "none";

    let mediaSupport = 'mediaDevices' in navigator;

    if( mediaSupport && null == cameraStream ) {

        navigator.mediaDevices.getUserMedia( { video: true } )
        .then( function( mediaStream ) {

            cameraStream = mediaStream;
            stream.srcObject = mediaStream;
            stream.play();

        })
        .catch( function( err ) {

            console.log( "Unable to access camera: " + err );
        });


    }
    else if (null != cameraStream){

        alert( 'Device is already streaming.' );

        return;
    }
    else {
        alert( 'Your browser does not support media devices.' );
        return
    }
}

// Stop Streaming
function stopStreaming() {

    window.location.replace("./");

    // if( null != cameraStream ) {
    //
    //     var track = cameraStream.getTracks()[ 0 ];
    //
    //     track.stop();
    //     stream.load();
    //
    //     cameraStream = null;
    //
    //     window.location.replace("./");
    //
    // }
}


function viewStream() {

    window.location.replace("./view.php");

}


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

    return formattedTime
}


// function dataURItoBlob( dataURI ) {
//
//     var byteString = atob( dataURI.split( ',' )[ 1 ] );
//     var mimeString = dataURI.split( ',' )[ 0 ].split( ':' )[ 1 ].split( ';' )[ 0 ];
//
//     var buffer	= new ArrayBuffer( byteString.length );
//     var data	= new DataView( buffer );
//
//     for( var i = 0; i < byteString.length; i++ ) {
//
//         data.setUint8( i, byteString.charCodeAt( i ) );
//     }
//
//     return new Blob( [ buffer ], { type: mimeString } );
// }

//toBlob polyfill
// if (!HTMLCanvasElement.prototype.toBlob) {
//   Object.defineProperty(HTMLCanvasElement.prototype, 'toBlob', {
//     value: function (callback, type, quality) {
//       var dataURL = this.toDataURL(type, quality).split(',')[1];
//       setTimeout(function() {
//         var binStr = atob( dataURL ),
//             len = binStr.length,
//             arr = new Uint8Array(len);
//         for (var i = 0; i < len; i++ ) {
//           arr[i] = binStr.charCodeAt(i);
//         }
//         callback( new Blob( [arr], {type: type || 'image/png'} ) );
//       });
//     }
//   });
// }


// start streaming by uploading camera images to server
function startUploading() {

    // no longer allow change of value
    document.getElementById("sliderRange").style.display = "none";
    document.getElementById("sliderRange2").style.display = "none";
    document.getElementById("btn-capture").style.display = "none";

    let separation = 0;
    for( let i = 0; i < streamThreadNum; i++ ) {
        setTimeout("captureSnapshot()", separation);
        separation += threadSeparationNumerator/streamThreadNum;
    }

}


function captureSnapshot() {

    if( null != cameraStream ) {

        var ctx = capture.getContext( '2d' );
        var img = new Image();

        ctx.drawImage( stream, 0, 0, capture.width, capture.height );
        //ctx.drawImage( stream, 0, 0, stream.videoWidth, stream.videoHeight );

//         img.src		= capture.toDataURL( "image/png" );
//         //img.width	= stream.videoWidth;
//         //img.height	= stream.videoHeight;

//         snapshot.innerHTML = '';
//         snapshot.appendChild( img ); // display captured image locally
        
        
        
        //**** send the image to server ****//
//         var request = new XMLHttpRequest();

//         request.open( "POST", "index.php?act=upload", true );
        
//         request.timeout = 5000; // time in milliseconds

//         var data	= new FormData();
//         var dataURI	= snapshot.firstChild.getAttribute( "src" );
//         var imageData   = dataURItoBlob( dataURI );
        //var imageData ; 

        // toBlob usage
//         ctx.canvas.toBlob(function (blob) {
//          console.log(blob); //access blob here
//             imageData = blob;
//          }, 'image/png', 0.5);
        
        ctx.canvas.toBlob((blob) => {
            const file = new File([blob], 'myimage1', {
                type: 'image/jpeg',
                lastModified: Date.now()
            });

            var data = new FormData();

            data.append( "image", file, "streamIMG" );


            var request = new XMLHttpRequest();

            request.open( "POST", "index.php?act=upload", true );

            request.timeout = xhrTimeout; // time in milliseconds


            request.send( data );

            request.onload = function() {
                if (request.status != 200) { 
                    // analyze HTTP status of the response
                    setTimeout("captureSnapshot()", 0);
                } 
                else {

                    var resp = JSON.parse(request.response);
                    var receiveTime = parseInt(resp.time,10);
                    timestampIndicator.innerHTML = timeBreakout(parseInt(resp.time,10));
                    
                    logTimestamp.push(receiveTime);
                    let logTimeSpan;
                    if (logTimestamp.length>logLength){
                        logTimestamp.shift(); // Remove an item from the beginning of an array
                        logTimeSpan = (logTimestamp[logTimestamp.length-1]-logTimestamp[0])/1000;
                        metricFrameRate.innerHTML = (logTimestamp.length/logTimeSpan).toFixed(2);
                    }

                    
                    let res = Array.from(data.entries(), ([key, prop]) => ( // get image size (content length)
                        {[key]: {
                          "ContentLength": 
                          typeof prop === "string" 
                          ? prop.length 
                          : prop.size
                        }
                      }));

                    logFileSize.push(res[0]['image']['ContentLength']);
                    if (logFileSize.length>logLength){
                        logFileSize.shift(); // Remove an item from the beginning of an array
                        let sum = 0;
                        for( let i = 0; i < logFileSize.length; i++ ){
                            sum += parseInt( logFileSize[i], 10 ); //don't forget to add the base
                        }
                        metricBitRate.innerHTML = (sum/1000/logTimeSpan).toFixed(2);
                    }

                    setTimeout("captureSnapshot()", 0);
                }
            };
            request.ontimeout = function (e) {
                // XMLHttpRequest timed out.
                setTimeout("captureSnapshot()", 0);
            };

        }, 'image/jpeg', imageQuality);
        
        
    }
    else{
        alert( 'No video feed detected.' );
    }  
    
}

</script></body>
</html>
    