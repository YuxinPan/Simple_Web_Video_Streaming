CXX = g++-9
CXXFLAGS = -std=c++17

yolo: path/to/yolov3-cpp/yolo.cpp
	${CXX} ${CXXFLAGS} path/to/date/src/tz.cpp path/to/yolov3-cpp/yolo.cpp -o path/to/yolov3-cpp/yolo `pkg-config --cflags --libs opencv4` -Ipath/to/date/include/  -lcurl

clean:
	rm -f *.o yolo
	rm -f *~