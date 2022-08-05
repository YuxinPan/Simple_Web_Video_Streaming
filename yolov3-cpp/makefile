CXX = g++-9
CXXFLAGS=-std=c++17

yolo: /root/YOLO/yolov3-cpp/yolo.cpp
	${CXX} ${CXXFLAGS} /root/YOLO/yolov3-cpp/date/src/tz.cpp /root/YOLO/yolov3-cpp/yolo.cpp -o /root/YOLO/yolov3-cpp/yolo `pkg-config --cflags --libs opencv4` -I/root/YOLO/yolov3-cpp/date/include/  -lcurl

clean:
	-rm yolo