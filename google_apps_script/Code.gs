/**
 * WMS - Warehouse & Logistics Management System
 * Complete Selular / Ocean Space
 * 
 * Google Apps Script Webhook
 * Menangani penambahan baris pengiriman dari CSA WMS ke sheet cabang yang sesuai,
 * membuat sheet otomatis dengan TEMPLATE & FONT YANG SAMA (Consolas 11pt) jika sheet belum ada,
 * tanpa pernah menimpa baris yang sudah ada. Data hanya dilewati jika identik (Nomor SJ + Total Nominal sama);
 * Nomor SJ yang sama dengan total berbeda tetap ditambahkan sebagai baris baru.
 * 
 * TARGET SPREADSHEET:
 * URL: https://docs.google.com/spreadsheets/d/1oBtqSEj0IZKzZAMQKquuyve242uKPEzShn4-uI92cCE/edit?gid=432982879#gid=432982879
 * Spreadsheet ID: 1oBtqSEj0IZKzZAMQKquuyve242uKPEzShn4-uI92cCE
 * 
 * PENERAPAN (DEPLOYMENT):
 * ID Penerapan: AKfycbysXbYPQ5JBm0hARJsWNH1mZ6Z7duvSwyMtgLduEDiMJDZfqDglhp6BOVc-sRDpam8Otg
 * Web App URL: https://script.google.com/macros/s/AKfycbysXbYPQ5JBm0hARJsWNH1mZ6Z7duvSwyMtgLduEDiMJDZfqDglhp6BOVc-sRDpam8Otg/exec
 * Script ini HARUS dibuat dari menu Ekstensi -> Apps Script di spreadsheet target di atas,
 * dan deployment Web App diatur "Execute as: Me" + "Who has access: Anyone" (Siapa saja).
 * 
 * =========================================================================
 * PANDUAN PENTING: CARA UPDATE DEPLOYMENT AGAR SCRIPT TERBARU INI AKTIF:
 * =========================================================================
 * 1. Buka spreadsheet Google Anda di browser.
 * 2. Klik menu: Ekstensi (Extensions) -> Apps Script.
 * 3. Hapus SEMUA kode yang ada di editor Apps Script, lalu Paste seluruh isi file ini.
 * 4. Klik ikon Simpan (Ctrl + S).
 * 5. Klik tombol biru "Deploy" (Terapkan) di kanan atas -> pilih "Manage deployments" (Kelola penerapan).
 * 6. Klik ikon Pensil (Edit) di samping nama deployment aktif.
 * 7. Di baris "Version", pilih "New version" (Versi baru).
 * 8. Klik tombol "Deploy" (Terapkan).
 * =========================================================================
 */

// Naikkan setiap kali file ini diubah, supaya versi yang ter-deploy bisa dicek lewat GET webhook
var SCRIPT_VERSION = "2026-10-09.3";

function doPost(e) {
  var lock = LockService.getScriptLock();
  // Tunggu lock maksimal 30 detik agar aman jika ada request bersamaan
  if (!lock.tryLock(30000)) {
    return ContentService.createTextOutput(JSON.stringify({
      status: "error",
      message: "Server sheet sedang sibuk, silakan coba beberapa saat lagi."
    })).setMimeType(ContentService.MimeType.JSON);
  }

  try {
    if (!e || !e.postData || !e.postData.contents) {
      return ContentService.createTextOutput(JSON.stringify({
        status: "error",
        message: "No POST body received."
      })).setMimeType(ContentService.MimeType.JSON);
    }

    var payload = JSON.parse(e.postData.contents);
    var sheetName = payload.sheetName;
    var rows = payload.rows; // Array baris (25 kolom per baris)
    // Reset warna biru data lama hanya di batch pertama yang menulis data dalam satu kali sinkron,
    // agar seluruh data baru dari sinkron yang sama tetap biru meski dikirim per 100 baris
    var resetHighlight = payload.resetHighlight !== false;

    if (!sheetName || !rows || !rows.length) {
      return ContentService.createTextOutput(JSON.stringify({
        status: "error",
        message: "Parameter sheetName atau rows kosong."
      })).setMimeType(ContentService.MimeType.JSON);
    }

    var ss = null;
    try {
      ss = SpreadsheetApp.getActiveSpreadsheet();
    } catch (err) {}

    // Fallback jika script berjalan mandiri atau butuh target eksplisit
    if (!ss) {
      var fallbackId = payload.spreadsheetId || "1oBtqSEj0IZKzZAMQKquuyve242uKPEzShn4-uI92cCE";
      ss = SpreadsheetApp.openById(fallbackId);
    }

    // Cari tab sheet yang SUDAH ADA di spreadsheet (case-insensitive & trim)
    var targetSheetClean = sheetName.toString().trim().toUpperCase();
    var sheet = null;
    var allSheets = ss.getSheets();

    for (var s = 0; s < allSheets.length; s++) {
      if (allSheets[s].getName().trim().toUpperCase() === targetSheetClean) {
        sheet = allSheets[s];
        break;
      }
    }

    // JIKA SHEET BELUM ADA:
    // Buat sheet baru secara otomatis dengan MENDUPLIKASI TEMPLATE yang sudah ada
    // agar font (Consolas), ukuran teks (11pt), warna header, lebar kolom, dan validasi 100% IDENTIK
    if (!sheet) {
      var templateSheet = null;
      var templateCandidates = ["CIREBON", "BANDUNG", "SURABAYA", "JAKARTA PIK"];
      for (var t = 0; t < templateCandidates.length; t++) {
        var cand = ss.getSheetByName(templateCandidates[t]);
        if (cand) {
          templateSheet = cand;
          break;
        }
      }
      if (!templateSheet && allSheets.length > 0) {
        templateSheet = allSheets[0];
      }

      if (templateSheet) {
        // Gandakan sheet acuan agar seluruh layout, warna, dan lebar kolom persis sama
        var copiedSheet = templateSheet.copyTo(ss);
        copiedSheet.setName(sheetName.toString().trim());

        // Pangkas baris agar tidak membebani sheet (sisakan 100 baris awal)
        var maxRows = copiedSheet.getMaxRows();
        if (maxRows > 100) {
          copiedSheet.deleteRows(101, maxRows - 100);
        }

        // Kosongkan isi data lama dari baris 2 ke bawah, tapi pertahankan format/validasi
        var curLastRow = copiedSheet.getLastRow();
        if (curLastRow > 1) {
          copiedSheet.getRange(2, 1, curLastRow - 1, copiedSheet.getMaxColumns()).clearContent();
        }

        sheet = copiedSheet;
      } else {
        // Fallback jika belum ada sheet sama sekali
        sheet = ss.insertSheet(sheetName.toString().trim());
        var defaultHeaders = [
          "TANGGAL ORDER", "TANGGAL KIRIM", "BADAN USAHA", "DEPO [WAREHOUSE]",
          "TUJUAN/DEALER", "ALAMAT KIRIM", "NAMA KOTA", "BRAND", "NOMOR SJ",
          "TOTAL NOMINAL SJ", "REFFNOTE", "QTY UNIT", "QTY KOLI", "BERAT",
          "KETENTUAN BIAYA KIRIM", "NAMA EKSPEDISI", "NO RESI AWB", "BIAYA KIRIM",
          "STATUS PEMBAYARAN", "STATUS PENGIRIMAN", "TANGGAL DITERIMA",
          "LEAD TIME PROSES", "LEAD TIME KIRIM", "LEAD TIME KESELURUHAN", "KET. ISI UNIT"
        ];
        sheet.appendRow(defaultHeaders);
        sheet.setFrozenRows(1);
        sheet.getRange(1, 1, 1, 25)
          .setFontFamily("Consolas")
          .setFontSize(11)
          .setFontWeight("bold")
          .setBackground("#b7e1cd")
          .setHorizontalAlignment("center");
      }
    }

    // Baris yang sudah ada di sheet TIDAK PERNAH ditimpa. Data baru hanya dilewati jika datanya identik:
    // Nomor SJ (kolom I) + Total Nominal SJ (kolom J) sama. Jika Nomor SJ sama tapi totalnya beda
    // (misal input manual admin sudah termasuk PPN), data tetap ditambahkan sebagai baris baru.
    var lastRow = sheet.getLastRow();
    var existingKeys = {};

    if (lastRow > 1) {
      var values = sheet.getRange(2, 9, lastRow - 1, 2).getValues();
      for (var i = 0; i < values.length; i++) {
        var existingKey = rowKey(values[i][0], values[i][1]);
        if (existingKey) {
          existingKeys[existingKey] = true;
        }
      }
    }

    var rowsToInsert = [];
    var skippedIndexes = []; // Posisi baris di payload yang dilewati karena data identik sudah ada
    for (var j = 0; j < rows.length; j++) {
      var row = rows[j];
      var newKey = rowKey(row[8], row[9]); // Index 8 = NOMOR SJ, index 9 = TOTAL NOMINAL SJ

      if (newKey && existingKeys[newKey]) {
        skippedIndexes.push(j);
      } else {
        rowsToInsert.push(row);
        if (newKey) {
          existingKeys[newKey] = true; // Tandai agar tidak duplikat dalam batch yang sama
        }
      }
    }

    var startRow = 0;
    var endRow = 0;

    // Tulis baris baru sekaligus secara batch di baris paling bawah
    if (rowsToInsert.length > 0) {
      startRow = sheet.getLastRow() + 1;
      var requiredRows = startRow + rowsToInsert.length - 1;

      // Pastikan kapasitas baris spreadsheet mencukupi sebelum setValues
      if (requiredRows > sheet.getMaxRows()) {
        sheet.insertRowsAfter(sheet.getMaxRows(), (requiredRows - sheet.getMaxRows()) + 20);
      }

      var insertRange = sheet.getRange(startRow, 1, rowsToInsert.length, rowsToInsert[0].length);

      // Dropdown di sheet (misal kolom P NAMA EKSPEDISI) yang disetel "Tolak input" akan menggagalkan
      // seluruh batch jika 1 nilai dari CSA tidak ada di daftar (misal "J&T Express", "Shopee Xpress").
      // Khusus baris baru ini, ubah ke "Tampilkan peringatan": data tetap masuk, dropdown tetap ada.
      var validations = insertRange.getDataValidations();
      var relaxed = false;
      for (var vr = 0; vr < validations.length; vr++) {
        for (var vc = 0; vc < validations[vr].length; vc++) {
          var rule = validations[vr][vc];
          if (rule && !rule.getAllowInvalid()) {
            validations[vr][vc] = rule.copy().setAllowInvalid(true).build();
            relaxed = true;
          }
        }
      }
      if (relaxed) {
        insertRange.setDataValidations(validations);
      }

      try {
        insertRange.setValues(rowsToInsert);
      } catch (writeErr) {
        // setValues bisa gagal di tengah jalan (misal validasi data) dan meninggalkan sebagian baris
        // tertulis. Hapus baris batch ini yang sempat tertulis agar tidak ada baris setengah jadi.
        var lastWritten = Math.min(sheet.getLastRow(), requiredRows);
        if (lastWritten >= startRow) {
          sheet.deleteRows(startRow, lastWritten - startRow + 1);
        }
        throw writeErr;
      }

      // KONSISTENSI FONT & UKURAN TEKS:
      // Seluruh baris data dijamin menggunakan font Consolas ukuran 11
      insertRange.setFontFamily("Consolas").setFontSize(11);

      // PEMBEDA DATA BARU MASUK:
      // 1. Reset warna teks Nomor SJ baris lama (sebelumnya) ke hitam normal
      if (resetHighlight && startRow > 2) {
        sheet.getRange(2, 9, startRow - 2, 1)
          .setFontColor("#000000")
          .setFontWeight("normal");
      }

      // 2. Beri warna biru dan teks tebal (bold) pada Kolom 9 (NOMOR SJ / Kolom I) khusus data yang baru masuk
      sheet.getRange(startRow, 9, rowsToInsert.length, 1)
        .setFontColor("#1a73e8")
        .setFontWeight("bold");

      // Format tanggal untuk Kolom 1 (Tgl Order), Kolom 2 (Tgl Kirim), Kolom 21 (Tgl Diterima)
      sheet.getRange(startRow, 1, rowsToInsert.length, 2).setNumberFormat("dd/MM/yyyy");
      sheet.getRange(startRow, 21, rowsToInsert.length, 1).setNumberFormat("dd/MM/yyyy");

      // Format nominal ribuan untuk Kolom 10 (Total SJ) dan Kolom 18 (Biaya Kirim)
      sheet.getRange(startRow, 10, rowsToInsert.length, 1).setNumberFormat("#,##0");
      sheet.getRange(startRow, 18, rowsToInsert.length, 1).setNumberFormat("#,##0");

      // Validasi Dropdown Badan Usaha pada Kolom C
      var buRule = SpreadsheetApp.newDataValidation()
        .requireValueInList(["PT. MEDIA SELULAR INDONESIA", "CV. TOP SELULAR", "CV. COMPLETE SELULAR"], true)
        .setAllowInvalid(true)
        .build();
      sheet.getRange(startRow, 3, rowsToInsert.length, 1).setDataValidation(buRule);

      endRow = requiredRows;
    }

    return ContentService.createTextOutput(JSON.stringify({
      status: "success",
      version: SCRIPT_VERSION,
      sheet: sheet.getName(),
      total_received: rows.length,
      inserted: rowsToInsert.length,
      skipped_duplicate: rows.length - rowsToInsert.length,
      skipped_indexes: skippedIndexes,
      start_row: startRow,
      end_row: endRow,
      sheet_last_row: sheet.getLastRow()
    })).setMimeType(ContentService.MimeType.JSON);

  } catch (err) {
    return ContentService.createTextOutput(JSON.stringify({
      status: "error",
      message: err.toString()
    })).setMimeType(ContentService.MimeType.JSON);
  } finally {
    lock.releaseLock();
  }
}

// Kunci pembanding data identik: Nomor SJ + total nominal (dibulatkan ke rupiah)
function rowKey(sj, total) {
  var sjText = String(sj === null || sj === undefined ? '' : sj).trim();
  if (!sjText) {
    return '';
  }
  return sjText + '|' + toRupiah(total);
}

// Nilai sel bisa berupa angka (15880000) atau teks ("Rp15.880.000" / "15,880,000.50")
function toRupiah(value) {
  if (typeof value === 'number') {
    return Math.round(value);
  }
  var text = String(value === null || value === undefined ? '' : value).replace(/rp/gi, '').trim();
  text = text.replace(/[.,]\d{1,2}$/, ''); // buang bagian sen
  var digits = text.replace(/\D/g, '');
  return digits ? parseInt(digits, 10) : 0;
}

// Endpoint GET untuk uji konektivitas dan menampilkan daftar sheet yang tersedia
function doGet(e) {
  var ss = null;
  try {
    ss = SpreadsheetApp.getActiveSpreadsheet();
  } catch (err) {}
  if (!ss) {
    ss = SpreadsheetApp.openById("1oBtqSEj0IZKzZAMQKquuyve242uKPEzShn4-uI92cCE");
  }

  var existingSheets = ss.getSheets().map(function(s) {
    return {
      name: s.getName(),
      last_row: s.getLastRow()
    };
  });

  return ContentService.createTextOutput(JSON.stringify({
    status: "active",
    version: SCRIPT_VERSION,
    message: "WMS Google Sheets Webhook is ready!",
    spreadsheet_name: ss.getName(),
    available_sheets: existingSheets
  })).setMimeType(ContentService.MimeType.JSON);
}

/**
 * Fungsi utilitas untuk menghapus tab sheet asing yang sempat terbuat otomatis
 * jika Anda ingin membersihkan tab-tab tersebut.
 * 
 * Jalankan fungsi ini dari Apps Script dengan memilih fungsi 'deleteUnwantedSheets'
 * lalu klik 'Run' (Jalankan).
 */
function deleteUnwantedSheets() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  if (!ss) {
    ss = SpreadsheetApp.openById("1oBtqSEj0IZKzZAMQKquuyve242uKPEzShn4-uI92cCE");
  }

  // Daftar sheet cabang resmi yang WAJIB dipertahankan (JANGAN DIHAPUS)
  var validSheets = [
    "CIREBON", "BANDUNG", "JAKARTA PIK", "JAKARTA PC",
    "PURWOKERTO", "SURABAYA", "SEMARANG", "RETUR",
    "MAKASSAR", "MEDAN", "PALEMBANG", "PEKANBARU",
    "PADANG", "LAMPUNG", "JAMBI", "BENGKULU",
    "MANADO", "PALU", "DENPASAR", "PONTIANAK", "BANJARMASIN", "SAMARINDA"
  ];

  var allSheets = ss.getSheets();
  var deletedCount = 0;

  for (var i = 0; i < allSheets.length; i++) {
    var name = allSheets[i].getName().trim().toUpperCase();
    var isValid = false;

    for (var v = 0; v < validSheets.length; v++) {
      if (validSheets[v] === name) {
        isValid = true;
        break;
      }
    }

    if (!isValid && (name.indexOf("SS") === 0 || name.indexOf("SP") === 0 || name === "GMOOS" || name === "GSB" || name === "MCRHP10" || name === "PRM01" || name === "GPWK" || name === "TEST_SYNC")) {
      Logger.log("Menghapus sheet: " + allSheets[i].getName());
      ss.deleteSheet(allSheets[i]);
      deletedCount++;
    }
  }

  Logger.log("Selesai! Berhasil membersihkan " + deletedCount + " sheet asing.");
}

/**
 * PEMBERSIHAN SEKALI PAKAI (09/10/2026):
 * Menghapus baris setengah jadi peninggalan batch yang gagal karena validasi dropdown (kolom P)
 * pada sinkron pertama. Setiap baris dicocokkan dulu dengan daftar Nomor SJ di bawah; jika ada
 * SATU saja yang tidak cocok, tab tersebut TIDAK dihapus sama sekali (aman dijalankan ulang).
 *
 * Cara pakai: di editor Apps Script pilih fungsi 'hapusBarisGagalSinkron' lalu klik 'Run' (Jalankan),
 * kemudian lihat hasilnya di 'Execution log' (Log eksekusi).
 */
function hapusBarisGagalSinkron() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  if (!ss) {
    ss = SpreadsheetApp.openById("1oBtqSEj0IZKzZAMQKquuyve242uKPEzShn4-uI92cCE");
  }

  var targets = [
    {
      sheet: "SURABAYA",
      startRow: 3434,
      sj: ("2609001644,2609001668,2609002327,2609005943,2609005970,2609014618,2609014619,2609014621,2609014622," +
        "2609014623,2609014624,2609014628,2609014653,2609014656,2609014658,2609014691,2609014705,2609014720," +
        "2609014728,2609014735,2609014752,2609015107,2609017657,2609017660,2609017669,2609017673,2609017953," +
        "2609017971,2609018028,2609018032,2609018072,2609018078,2609018132,2609018134,2609018144,2609018145").split(",")
    },
    {
      sheet: "JAKARTA PIK",
      startRow: 13989,
      sj: ("2609000003,2609000103,2609000205,2609000412,2609000413,2609000414,2609000517,2609000518,2609000519," +
        "2609000706,2609000888,2609000989,2609001089,2609001189,2609001368,2609001547,2609001548,2609001709," +
        "2609001809,2609001909,2609002009,2609002010,2609002163,2609002266,2609002454,2609002575,2609002677," +
        "2609002779,2609002933,2609003130,2609003283,2609003383,2609003483,2609003663,2609003843,2609003953," +
        "2609004053,2609004153,2609004253,2609004353,2609004455,2609004558,2609004756,2609004988,2609004989," +
        "2609005203,2609005204,2609005205,2609005303,2609005403,2609005573,2609005706,2609005707,2609005708," +
        "2609005709,2609005710,2609005876,2609006036,2609006138,2609006239,2609006341,2609006441,2609006542," +
        "2609006644,2609006745,2609006850,2609006951,2609007052,2609007154,2609007260,2609007364,2609007526," +
        "2609007641,2609007747,2609007854,2609007965,2609007966,2609007967").split(",")
    }
  ];

  for (var t = 0; t < targets.length; t++) {
    var target = targets[t];
    var sheet = ss.getSheetByName(target.sheet);
    if (!sheet) {
      Logger.log(target.sheet + ": tab tidak ditemukan, dilewati.");
      continue;
    }

    var count = target.sj.length;
    var values = sheet.getRange(target.startRow, 9, count, 1).getValues();
    var mismatch = [];
    for (var i = 0; i < count; i++) {
      if (String(values[i][0]).trim() !== target.sj[i]) {
        mismatch.push("baris " + (target.startRow + i) + " berisi '" + values[i][0] + "', seharusnya " + target.sj[i]);
      }
    }

    if (mismatch.length) {
      Logger.log(target.sheet + ": DIBATALKAN, tidak ada yang dihapus. " + mismatch.length +
        " baris tidak cocok, contoh: " + mismatch.slice(0, 3).join("; "));
      continue;
    }

    sheet.deleteRows(target.startRow, count);
    Logger.log(target.sheet + ": " + count + " baris (" + target.startRow + "-" + (target.startRow + count - 1) +
      ") berhasil dihapus.");
  }
}
