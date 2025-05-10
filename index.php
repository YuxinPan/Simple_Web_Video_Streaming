<?php
    
    // https://zocada.com/compress-resize-images-javascript-browser/
    // https://developer.mozilla.org/en-US/docs/Web/API/WebRTC_API/Taking_still_photos
    
    
    // include_once '../login/serviceServer.php';

    // auth check - commented out for public release
    /*
    $user = new authMaintainer();
    if (!($user->statusCheck())) {
        $query = array(
            'service' => $_SERVER['HTTP_HOST']
        );
        header("Location: https://" . join(DIRECTORY_SEPARATOR, array($config['SSOdomain'], $config['SSOrootPath'])) . "?" . http_build_query($query));        
        die();
    }
    */


    // if the request is an API call to upload image
    if ((isset($_GET['act'])) && (isset($_GET['timestamp'])) && ($_GET['act'] == "upload")) {

        $datapath = 'data/';
        $fileValidPeriod = 30 * 1000; // delete file after $fileValidPeriod milliseconds
        $file_ext = ".jpg";
        
        $fileName = $_FILES['image']['name'];
        $fileType = $_FILES['image']['type'];
        $fileContent = file_get_contents($_FILES['image']['tmp_name']);
        $dataUrl = 'data:' . $fileType;
        $millitimestamp = (int) $_GET['timestamp'];

        // delete file if outdated
        $allfiles = array_diff(scandir($datapath), array('.', '..', '.htaccess', '.ipynb_checkpoints'));
        $filetimestamps = array_map(function ($value) use ($millitimestamp, $fileValidPeriod, $datapath, $file_ext) { 
            $filetimestamp = basename($value, $file_ext); // file basename should also be a timestamp
            if ((is_numeric($filetimestamp)) // if not empty
                && (file_exists($datapath . $value))
                && ($filetimestamp < $millitimestamp - $fileValidPeriod)) { // if outdated
                unlink($datapath . $value);
            } 
        }, $allfiles);


        $f = file_put_contents($datapath . $millitimestamp . '.jpg', $fileContent); // remember to check folder permission

        $json = json_encode(array(
            'file_type' => $dataUrl,
            'name' => $fileName,
            'time' => $millitimestamp,
            'receiving_status' => $f,
        ));

        echo $json;
        
        // bring up machine learning inference (YOLO) if the inference process is not already on
        // if (strpos(strval(shell_exec('ps -A')),'inference-sub') === false) {
        //     $full_output = shell_exec('nohup python3 path/to/yolo.py > /dev/null 2>&1 &');
        // }

        die();
    }
?>

<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <title>Simple Web Video Streaming</title>
    <link rel="shortcut icon" href="assets/favicon.ico">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, minimum-scale=1.0">

    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/custom.js"></script>
    <link rel="stylesheet" href="assets/custom.css">

    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #fafafa;
            font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #333;
        }

        h1, h2, h3, h4, h5, h6 {
            margin: 0;
            padding: 0;
            font-weight: 600;
        }

        .button-group,
        .play-area {
            margin: 2em auto;
            max-width: 800px;
            background-color: #ededed;
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 1em;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .button,
        .btn {
            display: inline-block;
            margin-right: 1em;
            padding: 0.65em 1.4em;
            font-size: 1em;
            font-weight: 500;
            border: 1px solid #ccc;
            border-radius: 6px;
            background-color: #fff;
            color: #333;
            cursor: pointer;
            text-decoration: none;
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }
        
        .button:hover,
        .btn:hover {
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }
        
        .button:active,
        .btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .button:focus,
        .btn:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(170, 170, 170, 0.3);
        }

        .play-area-sub {
            display: inline-block;
            vertical-align: top;
            padding: 0.01em 0.01%;
            /* width: 47%; */
        }

        #capture {
            display: none;
        }
        
        #snapshot {
            display: none;
        }

        .slidecontainer {
            width: 100%;
            margin: 1em auto;
            max-width: 800px;
        }

        .slider {
            -webkit-appearance: none;
            width: 100%;
            height: 15px;
            border-radius: 5px;
            background: #d3d3d3;
            outline: none;
            opacity: 0.7;
            transition: opacity .2s;
            margin-top: 1em;
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

        #metricFrameRate,
        #metricBitRate {
            font-weight: bold;
            color: #11998e;
            font-size: 1.15em;
        }

        #timestampIndicator {
            margin-top: 0.75em;
            font-size: 0.9em; 
            color: #666;
            font-style: italic;
        }
    </style>
</head>
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
            <video id="stream" width="480" height="360"></video>
        </div>
        <br>
        <div class="play-area-sub">
            <canvas id="capture" width="480" height="360"></canvas>
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
        // Config object
        const CONFIG = {
            threadSeparation: 1000,          // millisecond, between invocation of each thread
            xhrTimeout: 5000,                // millisecond
            logLength: 5,                    // log for streaming metrics
            defaultThreadNum: 2,             // initial thread count
            defaultQuality: 0.85,            // initial image quality
            errorBackoffTime: 1000           // backoff time in ms when error occurs
        };

        // DOM elements
        const DOM = {
            btn: {
                start: document.getElementById("btn-start"),
                stop: document.getElementById("btn-stop"),
                capture: document.getElementById("btn-capture"),
                view: document.getElementById("btn-view")
            },
            stream: document.getElementById("stream"),
            canvas: document.getElementById("capture"),
            snapshot: document.getElementById("snapshot"),
            sliders: {
                threads: document.getElementById("sliderRange"),
                threadValue: document.getElementById("sliderValue"),
                quality: document.getElementById("sliderRange2"),
                qualityValue: document.getElementById("sliderValue2")
            },
            metrics: {
                timestampIndicator: document.getElementById("timestampIndicator"),
                frameRate: document.getElementById("metricFrameRate"),
                bitRate: document.getElementById("metricBitRate")
            }
        };

        // Variables that can be modified during runtime
        let streamThreadNum = CONFIG.defaultThreadNum;       // how many capture and upload threads
        let imageQuality = CONFIG.defaultQuality;            // stream image compression quality

        // Stream state tracking
        let logTimestamp = [];                  // log of timestamp for frame rate analysis with the array length of logLength
        let logFileSize = [];                   // log of image file size for bit rate analysis with the array length of logLength
        let cameraStream = null;                // The video stream

        // Initial setup when document is ready
        $(document).ready(function() {
            initializeUI();
            setupSliders();
            attachEventListeners();
        });

        // Initialize the UI
        function initializeUI() {
            // no stop or stream option when camera not started at first
            DOM.btn.stop.style.display = "none";
            DOM.btn.capture.style.display = "none";
            
            // for Safari Iphone compatibility
            DOM.stream.setAttribute("playsinline", true);
        }

        // Set up the sliders
        function setupSliders() {
            // Initialize slider values
            DOM.sliders.threadValue.innerHTML = DOM.sliders.threads.value;
            DOM.sliders.qualityValue.innerHTML = DOM.sliders.quality.value;
            
            // Slider input handlers
            DOM.sliders.threads.oninput = function() {
                DOM.sliders.threadValue.innerHTML = this.value;
                streamThreadNum = this.value;
            }
            
            DOM.sliders.quality.oninput = function() {
                DOM.sliders.qualityValue.innerHTML = this.value;
                imageQuality = this.value/100;
            }
        }

        // Attach event listeners
        function attachEventListeners() {
            DOM.btn.start.addEventListener("click", startStreaming);
            DOM.btn.stop.addEventListener("click", stopStreaming);
            DOM.btn.capture.addEventListener("click", startUploading);
            DOM.btn.view.addEventListener("click", viewStream);
        }

        // Start local camera, not uploading yet
        function startStreaming() {
            DOM.btn.stop.style.display = "inline";
            DOM.btn.capture.style.display = "inline";
            DOM.btn.start.style.display = "none";

            let mediaSupport = 'mediaDevices' in navigator;

            if (mediaSupport && cameraStream == null) {
                navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(mediaStream) {
                    cameraStream = mediaStream;
                    DOM.stream.srcObject = mediaStream;
                    DOM.stream.play();
                })
                .catch(function(err) {
                    console.log("Unable to access camera: " + err);
                });
            }
            else if (cameraStream != null) {
                alert('Device is already streaming.');
                return;
            }
            else {
                alert('Your browser does not support media devices.');
                return;
            }
        }

        // Stop Streaming
        function stopStreaming() {
            window.location.replace("./");
        }

        function viewStream() {
            window.location.replace("./view.php");
        }

        // Format time string
        function timeBreakout(inputTime) {
            let dateObj = new Date(inputTime);

            let months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            let year = dateObj.getFullYear();
            let month = months[dateObj.getMonth()];
            let date = dateObj.getDate();
            let hour = dateObj.getHours();
            let min = dateObj.getMinutes();
            let sec = dateObj.getSeconds();
            let formattedTime = year + '-' + month + '-' + date + '  ' + hour + ':' + min + ':' + sec;

            return formattedTime;
        }

        // Update metrics with new data
        function updateMetrics(timestamp, fileSize, displayRounding) {
            logTimestamp.push(timestamp);
            let logTimeSpan;
            
            if (logTimestamp.length > CONFIG.logLength) {
                logTimestamp.shift(); // Remove an item from the beginning of an array
                logTimeSpan = (logTimestamp[logTimestamp.length-1] - logTimestamp[0]) / 1000;
                DOM.metrics.frameRate.innerHTML = (logTimestamp.length/logTimeSpan).toFixed(displayRounding);
            }
            
            logFileSize.push(fileSize);
            if (logFileSize.length > CONFIG.logLength) {
                logFileSize.shift(); // Remove an item from the beginning of an array
                let sum = 0;
                for (let i = 0; i < logFileSize.length; i++) {
                    sum += parseInt(logFileSize[i], 10); //don't forget to add the base
                }
                DOM.metrics.bitRate.innerHTML = (sum/1000/logTimeSpan).toFixed(displayRounding);
            }
        }

        // Start streaming by uploading camera images to server
        function startUploading() {
            // no longer allow change of value
            DOM.sliders.threads.style.display = "none";
            DOM.sliders.quality.style.display = "none";
            DOM.btn.capture.style.display = "none";

            let separation = 0;
            for (let i = 0; i < streamThreadNum; i++) {
                setTimeout("captureSnapshot()", separation);
                separation += CONFIG.threadSeparation/streamThreadNum;
            }
        }

        // Create a file from a canvas blob
        function createFileFromBlob(blob) {
            return new File([blob], 'myimage1', {
                type: 'image/jpeg',
                lastModified: Date.now()
            });
        }

        // Process the capture response
        function processCaptureResponse(resp, data, displayRounding) {
            let receiveTime = parseInt(resp.time, 10);
            DOM.metrics.timestampIndicator.innerHTML = timeBreakout(parseInt(resp.time, 10));
            
            let res = Array.from(data.entries(), ([key, prop]) => ( // get image size (content length)
                {[key]: {
                    "ContentLength": 
                    typeof prop === "string" 
                    ? prop.length 
                    : prop.size
                }
            }));
            
            updateMetrics(receiveTime, res[0]['image']['ContentLength'], displayRounding);
        }

        function captureSnapshot() {
            let displayRounding = 1;

            if (cameraStream != null) {
                let ctx = DOM.canvas.getContext('2d');

                ctx.drawImage(DOM.stream, 0, 0, DOM.canvas.width, DOM.canvas.height);
                
                ctx.canvas.toBlob((blob) => {
                    const file = createFileFromBlob(blob);
                    var data = new FormData();
                    data.append("image", file, "streamIMG");

                    var request = new XMLHttpRequest();
                    request.open("POST", "index.php?act=upload&timestamp=" + String(Date.now()), true);
                    request.timeout = CONFIG.xhrTimeout; // time in milliseconds

                    request.send(data);

                    request.onload = function() {
                        if (request.status != 200) { 
                            // analyze HTTP status of the response
                            // Use backoff time for error retries
                            setTimeout("captureSnapshot()", CONFIG.errorBackoffTime);
                        } 
                        else {
                            let resp = JSON.parse(request.response);
                            processCaptureResponse(resp, data, displayRounding);
                            setTimeout("captureSnapshot()", 0);
                        }
                    };
                    
                    request.ontimeout = function (e) {
                        // XMLHttpRequest timed out.
                        // Use backoff time for timeout retries
                        setTimeout("captureSnapshot()", CONFIG.errorBackoffTime);
                    };
                }, 'image/jpeg', imageQuality);
            }
            else {
                alert('No video feed detected.');
            }
        }
    </script>
</body>
</html>
