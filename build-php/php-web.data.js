var createPhpModule = typeof createPhpModule !== 'undefined' ? createPhpModule : {};
createPhpModule['expectedDataFileDownloads'] ??= 0;
createPhpModule['expectedDataFileDownloads']++;
(() => {
  var isPthread = typeof ENVIRONMENT_IS_PTHREAD !== 'undefined' && ENVIRONMENT_IS_PTHREAD;
  var isWasmWorker = typeof ENVIRONMENT_IS_WASM_WORKER !== 'undefined' && ENVIRONMENT_IS_WASM_WORKER;
  if (isPthread || isWasmWorker) return;
  var isNode = false;
  function loadPackage(metadata) {
    function assert(check, msg) {
      if (!check) throw msg + new Error().stack;
    }
    createPhpModule['FS_createPath']("\/", "test_dir", true, true);
    createPhpModule['FS_createPath']("\/test_dir", "subdir", true, true);
    /** @constructor */
    function DataRequest(start, end, audio) {
      this.start = start;
      this.end = end;
      this.audio = audio;
    }
    DataRequest.prototype = {
      requests: {},
      open: function(mode, name) {
        this.name = name;
        this.requests[name] = this;
        createPhpModule['addRunDependency']('fp ' + this.name);
      },
      send: function() {},
      onload: function() {
        var byteArray = this.byteArray.subarray(this.start, this.end);
        this.finish(byteArray);
      },
      finish: function(byteArray) {
        var that = this;
                // Check if the file exists in the cache
        var cacheKey = 'FILE_DATA_' + this.name;
        var cachedData = typeof createPhpModule !== 'undefined' && createPhpModule['GL'] ? createPhpModule['GL'].loadCache(cacheKey) : null;
        if (cachedData) {
          createPhpModule['FS_createDataFile'](this.name, null, cachedData, true, true, true);
          createPhpModule['removeRunDependency']('fp ' + that.name);
        } else {
          // Data not in cache, create file and potentially store in cache
          createPhpModule['FS_createDataFile'](this.name, null, byteArray, true, true, true);
          if (typeof createPhpModule !== 'undefined' && createPhpModule['GL']) createPhpModule['GL'].storeCache(cacheKey, byteArray);
          createPhpModule['removeRunDependency']('fp ' + that.name);
        }
        this.requests[this.name] = null;
      }
    };

    var files = metadata['files'];
    for (var i = 0; i < files.length; ++i) {
      new DataRequest(files[i]['start'], files[i]['end'], files[i]['audio'] || 0).open('GET', files[i]['filename']);
    }
    function fetchRemotePackage(packageName, packageSize, callback, errback) {
      var xhr = new XMLHttpRequest();
      xhr.open('GET', packageName, true);
      xhr.responseType = 'arraybuffer';
      xhr.onprogress = function(event) {
        var url = packageName;
        var size = packageSize;
        if (event.total) size = event.total;
        if (event.loaded) {
          if (!xhr.addedTotal) {
            xhr.addedTotal = true;
            if (!createPhpModule.dataFileDownloads) createPhpModule.dataFileDownloads = {};
            createPhpModule.dataFileDownloads[url] = {
              loaded: event.loaded,
              total: size
            };
          }
          createPhpModule.dataFileDownloads[url].loaded = event.loaded;
          var total = 0;
          var loaded = 0;
          var num = 0;
          for (var download in createPhpModule.dataFileDownloads) {
            var data = createPhpModule.dataFileDownloads[download];
            total += data.total;
            loaded += data.loaded;
            num++;
          }
          total = Math.ceil(total * createPhpModule.expectedDataFileDownloads / num);
          if (createPhpModule.setStatus) createPhpModule.setStatus('Downloading data... (' + loaded + '/' + total + ')');
        } else if (!total) {
          if (createPhpModule.setStatus) createPhpModule.setStatus('Downloading data...');
        }
      };
      xhr.onerror = function(event) {
        throw new Error("NetworkError for: " + packageName);
      };
      xhr.onload = function(event) {
        if (xhr.status == 200 || xhr.status == 304 || xhr.status == 206 || (xhr.status == 0 && xhr.response)) { // file URLs can return 0
          var packageData = xhr.response;
          callback(new Uint8Array(packageData));
        } else {
          throw new Error(xhr.statusText + " : " + xhr.responseURL);
        }
      };
      xhr.send(null);
    };
    function handleError(error) {
      console.error('package error:', error);
    };
    var fetchedCallback = null;
    var fetchedErrback = null;
    var remotePackageName = "build-php\/php-web.data";
    var remotePackageSize = 41;
    createPhpModule['addRunDependency']('datafile_' + remotePackageName);
    if (typeof createPhpModule.locateFile === 'function') {
      remotePackageName = createPhpModule.locateFile(remotePackageName, '');
    }
    fetchRemotePackage(remotePackageName, remotePackageSize, (byteArray) => {
      var useData = `
          DataRequest.prototype.byteArray = byteArray;
          var files = metadata['files'];
          for (var i = 0; i < files.length; ++i) {
            DataRequest.prototype.requests[files[i].filename].onload();
          }
          createPhpModule['removeRunDependency']('datafile_' + remotePackageName);
      `;
      if (metadata['LZ4']) {
        if (typeof LZ4 === 'undefined') {
           console.error("LZ4 decoder not found. Make sure lz4.js is included.");
           throw new Error("LZ4 decoder missing");
        }
        console.log("Decompressing " + byteArray.length + " bytes of LZ4 data");
        try {
          var lz4Metadata = metadata; // LZ4 metadata is merged into the main metadata
          var decompressedSize = lz4Metadata['originalSize'];
          var lz4 = new LZ4();
          var decompressedData = lz4.decompress(byteArray, decompressedSize);
          byteArray = decompressedData; // Use the decompressed data
          console.log("Decompressed data size: " + byteArray.length);
        } catch (e) {
          console.error('LZ4 decompression failed:', e);
          throw new Error('Failed to decompress LZ4 data: ' + (e.message || e));
        }
      }
      eval(useData); // Use eval to execute the code string
    }, (err) => {
      throw err;
    });
  }
  loadPackage({"files":[{"filename":"/test_dir/include.txt","start":0,"end":13,"audio":0},{"filename":"/test_dir/subdir/another.txt","start":13,"end":26,"audio":0},{"filename":"/test_dir/subdir/temp.tmp","start":26,"end":41,"audio":0}]});
})();
