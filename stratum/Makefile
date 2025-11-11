# Modified for Badcoin project maintenance (c) 2025 The Badcoin Project
# Modernized Makefile for Yiimp Stratum (Debian 12 / GCC 12+)

CXX       := g++
CXXFLAGS  := -O2 -march=native -std=c++17 -Wall -Wno-unused-result -fPIC
CXXFLAGS += -I/usr/include/x86_64-linux-gnu
CXXFLAGS += -I/usr/include/mariadb
CPPFLAGS  := -DHAVE_CURL -DNO_EXCHANGE
SQLFLAGS  := $(shell mysql_config --cflags)
LDFLAGS   := $(shell mysql_config --libs)
LDLIBS    := iniparser/libiniparser.a algos/libalgos.a sha3/libhash.a \
             -lpthread -lgmp -lm -lstdc++ -lssl -lcrypto

# Curl
LDCURL    := $(shell pkg-config --libs libcurl)
LDFLAGS   += $(LDCURL)

SOURCES := stratum.cpp db.cpp coind.cpp coind_aux.cpp coind_template.cpp coind_submit.cpp \
           util.cpp list.cpp rpc.cpp job.cpp job_send.cpp job_core.cpp merkle.cpp share.cpp \
           socket.cpp coinbase.cpp client.cpp client_submit.cpp client_core.cpp \
           client_difficulty.cpp remote.cpp remote_template.cpp user.cpp object.cpp \
           json.cpp base58.cpp rpc_curl.cpp

OBJECTS := $(SOURCES:.cpp=.o)
OUTPUT  := stratum

CODEDIRS := algos sha3

.PHONY: all clean install $(CODEDIRS)

all: $(CODEDIRS) $(OUTPUT)

$(CODEDIRS):
	$(MAKE) -C $@

$(OUTPUT): $(OBJECTS)
	$(CXX) $(OBJECTS) $(LDFLAGS) $(LDLIBS) -o $@

%.o: %.cpp
	$(CXX) $(CXXFLAGS) $(CPPFLAGS) $(SQLFLAGS) -c $< -o $@

clean:
	rm -f $(OBJECTS) $(OUTPUT)
	for d in $(CODEDIRS); do $(MAKE) -C $$d clean; done

install: all
	strip -s $(OUTPUT)
	install -m 755 $(OUTPUT) /usr/local/bin/
