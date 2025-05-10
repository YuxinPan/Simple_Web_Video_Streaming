/****

Compile using the following command:
g++-9 -std=c++17 /root/YOLO/yolov3-cpp/date/src/tz.cpp \
/root/YOLO/yolov3-cpp/yolo.cpp -o /root/YOLO/yolov3-cpp/yolo \
`pkg-config --cflags --libs opencv4` \
-I/root/YOLO/yolov3-cpp/date/include/  -lcurl

****/

#include <fstream>
#include <sstream>
#include <iostream>
#include <chrono>
#include <ctime>
#include <thread>
#include <filesystem>
#include <unistd.h>
#include <vector>
#include <string>
#include <algorithm>

#include <opencv2/dnn.hpp>
#include <opencv2/imgproc.hpp>
#include <opencv2/highgui.hpp>

#include "date/include/date/tz.h"


using namespace cv;
using namespace dnn;

struct YoloConfig {
    // Detection and network parameters
    float confThreshold = 0.365f;  // Confidence threshold, 0.31 for person
    float nmsThreshold = 0.30f;    // Non-maximum suppression threshold
    
    int inpWidth = 416;            // Width of network's input image
    int inpHeight = 416;           // Height of network's input image
    
    // System parameters
    int nicePriority = 10;         // nice value for the process
    int cacheOutputNum = 500;      // cache output detected file numbers
    int sleep_interval = 100;      // sleep after one inference, in ms
    
    std::string pathNN = "/root/YOLO/yolov3-cpp/";
    std::string pathImage = "/var/www/apps.panyuxin.com/public_html/streaming/data/";
    std::string pathOutput = "/var/www/panyuxin.com/public_html/cloud/share/detection/";
    
    // Classes of interest
    std::vector<std::string> interestClasses = {"cat", "person", "dog"};
};

YoloConfig config;

std::vector<std::string> classes;

// Remove the bounding boxes with low confidence using non-maxima suppression
bool postprocess(Mat &frame, const std::vector<Mat> &out);

// Draw the predicted bounding box
void drawPred(int classId, float conf, int left, int top, int right, int bottom, Mat &frame);

// Get the names of the output layers
std::vector<std::string> getOutputsNames(const Net &net);


int main()
{
    nice(config.nicePriority);

    // Load names of classes
    std::string line,
        classesFile = config.pathNN + "coco.names",
        device = "cpu",
        lastFile = "",
        imgExt = ".jpg";
    std::ifstream ifs(classesFile.c_str());
    while (getline(ifs, line))
        classes.push_back(line);

    // Give the configuration and weight files for the model
    std::string modelConfiguration = config.pathNN + "yolov3-tiny.cfg";
    std::string modelWeights = config.pathNN + "yolov3-tiny.weights";

    // Load the network
    Net net = readNetFromDarknet(modelConfiguration, modelWeights);

    std::cout << "Using " << device << " device" << std::endl << std::endl;
    net.setPreferableBackend(DNN_TARGET_CPU);

    while (1) {
        std::string outputFile;
        Mat frame, blob;
        clock_t time_1 = clock(), time_2, time_3;

        try
        {
            long int newestTimestamp = 0;
            for (const auto & entry : std::filesystem::directory_iterator(config.pathImage)) {
                std::filesystem::path p(entry.path());
                std::string fileBasename = p.stem();
                if ( (std::all_of(fileBasename.begin(), fileBasename.end(), ::isdigit)) && // Use ::isdigit
                     (p.extension() == imgExt) ){
                    if ( newestTimestamp < std::stol(fileBasename) ) {
                        newestTimestamp = std::stol(fileBasename);
                    }
                }
            }

            // Open the image file
            outputFile = config.pathImage + std::to_string(newestTimestamp) + imgExt;
            std::cout << std::endl << std::endl << "Input: " << outputFile << std::endl;
            if (outputFile.compare(lastFile) == 0) { // if same as last file then
                std::this_thread::sleep_for(std::chrono::milliseconds(config.sleep_interval)); // sleep in the loop
                continue;
            }
            lastFile = outputFile;
            std::ifstream ifile(outputFile);
            if (!ifile) {
                throw("Error loading input file.");
            }

            frame = imread(outputFile);
            // outputFile.replace(outputFile.end() - 4, outputFile.end(), "_yolo_out.jpg");

            /**** Process frames ****/

            // Create a 4D blob from a frame. No cropping
            blobFromImage(frame, blob, 1 / 255.0, cv::Size(config.inpWidth, config.inpHeight), Scalar(0, 0, 0), true, false, CV_32F);

            time_2 = clock();
            printf("Preloading: %7.2f ms\n", ((float) time_2 - time_1)/CLOCKS_PER_SEC*1000);

            // Sets the input to the network
            net.setInput(blob);

            // Runs the forward pass to get output of the output layers
            std::vector<Mat> outs;
            net.forward(outs, getOutputsNames(net));

            // Remove the bounding boxes with low confidence
            bool foundObject = postprocess(frame, outs);

            // Put efficiency information.
            // The function getPerfProfile returns the overall time for inference(t)
            // and the timings for each of the layers(in layersTimes)
            std::vector<double> layersTimes;
            double freq = getTickFrequency() / 1000;
            double t = net.getPerfProfile(layersTimes) / freq;
            // string label = format("Inference: %8.2f ms", t);
            // putText(frame, label, Point(0, 25), FONT_HERSHEY_SIMPLEX, 0.8, Scalar(255, 0, 255), 1);

            std::string label = format("Inference: %8.2f ms", t);
            std::cout << label << std::endl;

            /**** Output processed image ****/
            if (foundObject) {
                time_1 = clock();

                // char buff[20];
                // std::time_t now = time(NULL);
                // std::strftime(buff, 20, "%Y-%m-%d %H-%M-%S", std::localtime(&now));
                // std::to_string(newestTimestamp)

                // EST EDT time using the date library
                // floor() to milliseconds because formatting second decimal places is not supported
                auto time_now = date::make_zoned("EST5EDT", std::chrono::floor<std::chrono::milliseconds>(
                                                std::chrono::system_clock::now()));

                outputFile = config.pathOutput + format("%F %H:%M:%S %Z", time_now) + imgExt;

                Mat detectedFrame;
                frame.convertTo(detectedFrame, CV_8U);
                imwrite(outputFile, detectedFrame); // Write the frame with the detection boxes

                // delete old cache files if existing file number larger than cacheOutputNum
                std::vector<std::string> allOutputFiles; // all images in the output folder
                for (const auto & entry : std::filesystem::directory_iterator(config.pathOutput)) {
                    std::filesystem::path p(entry.path());
                    if (p.extension() == imgExt){
                        allOutputFiles.push_back(p.filename().string()); // Use .string()
                    }
                }
                if (allOutputFiles.size() > config.cacheOutputNum) { // if too many cache files
                    std::sort(allOutputFiles.begin(), allOutputFiles.end());
                    for (size_t i = 0; i < (allOutputFiles.size() - config.cacheOutputNum); ++i) { // Use size_t for index
                        std::string fileToDelete = config.pathOutput + allOutputFiles.front();
                        std::filesystem::path p(fileToDelete);
                        try {
                            if (!std::filesystem::remove(p)) // Pass path object
                                std::cout << "Output file not found: " << p.filename().string() << std::endl;
                            }
                        catch(const std::filesystem::filesystem_error& err) { // if delete file error
                            std::cout << "Delete file error: " << err.what() << "\nwhen deleting " << p.filename().string();
                        }
                        allOutputFiles.erase(allOutputFiles.begin()); // delete first element
                    }
                }

                time_2 = clock();
                label = format("Outputting: %7.2f ms", ((float) time_2 - time_1)/CLOCKS_PER_SEC*1000);
                std::cout << label << std::endl << "Output: " << outputFile << std::endl;

            }
        }
        catch (const std::exception& e) // Catch specific exceptions
        {
             std::cerr << "Error: " << e.what() << std::endl; // Use cerr for errors
        }
        catch (...)
        {
            std::cerr << "Could not process the input image due to an unknown error" << std::endl; // Use cerr for errors
        }

        // sleep in the loop
        std::this_thread::sleep_for(std::chrono::milliseconds(config.sleep_interval));
    }

    return 0;
}


// Remove the bounding boxes with low confidence using non-maxima suppression
bool postprocess(Mat &frame, const std::vector<Mat> &out)
{
    std::vector<int> classIds;
    std::vector<float> confidences;
    std::vector<Rect> boxes;

    for (size_t i = 0; i < out.size(); ++i)
    {
        // Scan through all the bounding boxes output from the network and keep only the
        // ones with high confidence scores. Assign the box's class label as the class
        // with the highest score for the box.
        float *data = (float *)out[i].data;
        for (int j = 0; j < out[i].rows; ++j, data += out[i].cols)
        {
            Mat scores = out[i].row(j).colRange(5, out[i].cols);
            Point classIdPoint;
            double confidence;
            // Get the value and location of the maximum score
            minMaxLoc(scores, 0, &confidence, 0, &classIdPoint);
            if (confidence > config.confThreshold)
            {
                int centerX = (int)(data[0] * frame.cols);
                int centerY = (int)(data[1] * frame.rows);
                int width = (int)(data[2] * frame.cols);
                int height = (int)(data[3] * frame.rows);
                int left = centerX - width / 2;
                int top = centerY - height / 2;

                classIds.push_back(classIdPoint.x);
                confidences.push_back((float)confidence);
                boxes.push_back(Rect(left, top, width, height));
            }
        }
    }

    // Perform non maximum suppression to eliminate redundant overlapping boxes with
    // lower confidences
    std::vector<int> indices;
    NMSBoxes(boxes, confidences, config.confThreshold, config.nmsThreshold, indices);
    bool ObjectOfInterest = false;
    for (size_t i = 0; i < indices.size(); ++i) {
        int idx = indices[i];
        Rect box = boxes[idx];
        drawPred(classIds[idx], confidences[idx], box.x, box.y,
                 box.x + box.width, box.y + box.height, frame);

        // Check if the detected class is in the list of interest classes
        const std::string& detectedClass = classes[classIds[idx]];
        if (std::find(config.interestClasses.begin(), config.interestClasses.end(), detectedClass) != config.interestClasses.end()) {
            ObjectOfInterest = true;
            // Optionally break here if finding one is enough: break;
        }
    }
    return ObjectOfInterest;
}

// Draw the predicted bounding box
void drawPred(int classId, float conf, int left, int top, int right, int bottom, Mat &frame)
{
    // Draw a rectangle displaying the bounding box
    rectangle(frame, Point(left, top), Point(right, bottom), Scalar(255, 178, 50), 1);

    // Get the label for the class name and its confidence
    std::string label = format("%.2f", conf);
    if (!classes.empty())
    {
        CV_Assert(classId >= 0 && classId < (int)classes.size()); // Add boundary check
        label = classes[classId] + ":" + label;
    }

    // Display the label at the top of the bounding box
    int baseLine;
    Size labelSize = getTextSize(label, FONT_HERSHEY_SIMPLEX, 0.3, 1, &baseLine);
    top = max(top, labelSize.height);
    // Ensure the label background doesn't go out of bounds
    int label_right = left + round(1.5 * labelSize.width);
    int label_bottom = top + baseLine;
    rectangle(frame, Point(left, top - round(1.5 * labelSize.height)),
                   Point(label_right, label_bottom),
                   Scalar(255, 255, 255), FILLED);
    putText(frame, label, Point(left, top), FONT_HERSHEY_SIMPLEX, 0.45, Scalar(0, 0, 0), 1);
}

// Get the names of the output layers
std::vector<std::string> getOutputsNames(const Net &net)
{
    static std::vector<std::string> names;
    if (names.empty())
    {
        // Get the indices of the output layers, i.e. the layers with unconnected outputs
        std::vector<int> outLayers = net.getUnconnectedOutLayers();

        // get the names of all the layers in the network
        std::vector<std::string> layersNames = net.getLayerNames();

        // Get the names of the output layers in names
        names.resize(outLayers.size());
        for (size_t i = 0; i < outLayers.size(); ++i)
            names[i] = layersNames[outLayers[i] - 1];
    }
    return names;
}
