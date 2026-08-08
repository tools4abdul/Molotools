(function () {
  'use strict';

  function loadImageFromFile(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();

      function readExifOrientation(arrayBuffer) {
        var view = new DataView(arrayBuffer);
        if (view.byteLength < 2) {
          return 1;
        }

        var littleEndian = view.getUint8(0) === 0xFF && view.getUint8(1) === 0xD8;
        if (!littleEndian) {
          return 1;
        }

        var offset = 2;
        while (offset < view.byteLength) {
          if (view.getUint8(offset) !== 0xFF) {
            break;
          }
          offset += 1;

          var marker = view.getUint8(offset);
          offset += 1;

          if (marker === 0xE1) {
            var length = view.getUint16(offset, false);
            offset += 2;

            var exifHeader = view.getUint32(offset, false);
            if (exifHeader === 0x45786966) {
              var exifData = offset + 4;
              var byteOrder = view.getUint16(exifData, false);
              var isLittleEndian = byteOrder === 0x4949;

              var offset_ifd0 = exifData + view.getUint32(exifData + 4, isLittleEndian);
              var numberOfDirectory = view.getUint16(offset_ifd0, isLittleEndian);

              for (var i = 0; i < numberOfDirectory; i += 1) {
                var ifdOffset = offset_ifd0 + 2 + i * 12;
                var tag = view.getUint16(ifdOffset, isLittleEndian);

                if (tag === 0x0112) {
                  var value = view.getUint16(ifdOffset + 8, isLittleEndian);
                  return Math.min(8, Math.max(1, value));
                }
              }
            }
            break;
          } else if (marker === 0xD9) {
            break;
          } else if (marker >= 0xD0 && marker <= 0xD8) {
            offset += 1;
          } else {
            var segmentLength = view.getUint16(offset, false);
            offset += segmentLength;
          }
        }

        return 1;
      }

      reader.onload = function (event) {
        var image = new Image();

        image.onload = function () {
          image.exifOrientation = 1;
          resolve(image);
        };

        image.onerror = function () {
          reject(new Error('Unable to read image data.'));
        };

        image.src = event.target.result;
      };

      reader.onload_binary = function (binaryEvent) {
        var arrayBuffer = binaryEvent.target.result;
        var orientation = readExifOrientation(arrayBuffer);
        var dataUrl = reader.result;

        var image = new Image();
        image.exifOrientation = orientation;

        image.onload = function () {
          resolve(image);
        };

        image.onerror = function () {
          reject(new Error('Unable to read image data.'));
        };

        image.src = dataUrl;
      };

      reader.onerror = function () {
        reject(new Error('Unable to read selected file.'));
      };

      var fileType = (file.type || '').toLowerCase();
      if (fileType === 'image/jpeg' || fileType === 'image/jpg') {
        reader.onload = function (event) {
          var dataUrl = event.target.result;
          var binaryReader = new FileReader();
