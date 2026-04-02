<?php
// ==========================================
// BACKEND: ROUTER & PENGOLAHAN DATA
// ==========================================
$settingsFile = 'settings.json';
$historyFile = 'history.json';

// Inisialisasi default settings
$settings = [
    'github_username' => '',
    'github_repo' => '',
    'github_token' => '',
    'connected' => false
];

if (file_exists($settingsFile)) {
    $fileContent = file_get_contents($settingsFile);
    $decoded = json_decode($fileContent, true);
    if ($decoded) $settings = array_merge($settings, $decoded);
}

// Inisialisasi Data Riwayat
$historyData = [];
if (file_exists($historyFile)) {
    $historyContent = file_get_contents($historyFile);
    $decodedHistory = json_decode($historyContent, true);
    if (is_array($decodedHistory)) $historyData = $decodedHistory;
}

// Helper Function: Upload ke GitHub via cURL
function uploadToGitHub($username, $repo, $token, $path, $base64Content) {
    $url = "https://api.github.com/repos/" . rawurlencode($username) . "/" . rawurlencode($repo) . "/contents/" . str_replace(' ', '_', $path);
    
    $data = json_encode([
        'message' => 'Upload ' . basename($path) . ' via App',
        'content' => $base64Content
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        "User-Agent: HAMIDAN-AR-App",
        "Accept: application/vnd.github.v3+json",
        "Content-Type: application/json"
    ]);
    
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        "code" => $httpCode,
        "response" => json_decode($response, true),
        "error" => $error
    ];
}

// Helper Function: Request ke GitHub API (GET/DELETE)
function requestGitHubApi($method, $username, $repo, $token, $path = '', $data = []) {
    $url = "https://api.github.com/repos/" . rawurlencode($username) . "/" . rawurlencode($repo) . "/contents/" . str_replace(' ', '_', $path);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($method === 'DELETE' && !empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        "User-Agent: HAMIDAN-AR-App",
        "Accept: application/vnd.github.v3+json",
        "Content-Type: application/json"
    ]);
    
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        "code" => $httpCode,
        "response" => json_decode($response, true),
        "error" => $error
    ];
}

// Handle POST Requests (AJAX & Form Submit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ACTION: PENGATURAN GITHUB
    if ($action === 'save_github') {
        $settings['github_username'] = trim($_POST['github_username'] ?? '');
        $settings['github_repo'] = trim($_POST['github_repo'] ?? '');
        $settings['github_token'] = trim($_POST['github_token'] ?? '');
        
        $settings['connected'] = (!empty($settings['github_username']) && !empty($settings['github_repo']) && !empty($settings['github_token']));
        
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
        
        header("Location: index.php");
        exit;
    }

    // 2. ACTION: HAPUS RIWAYAT (AJAX)
    if ($action === 'delete_history') {
        header('Content-Type: application/json');
        $folderToDelete = basename($_POST['folder'] ?? '');

        if (!empty($folderToDelete)) {
            
            // Cek apakah terkoneksi dengan GitHub
            if ($settings['connected'] && !empty($settings['github_token'])) {
                // GET daftar file dari folder di GitHub
                $getFiles = requestGitHubApi('GET', $settings['github_username'], $settings['github_repo'], $settings['github_token'], $folderToDelete);
                
                // Jika folder ditemukan (kode 200) dan response berupa array
                if ($getFiles['code'] === 200 && is_array($getFiles['response'])) {
                    // Looping untuk menghapus setiap file di dalam folder tersebut
                    foreach ($getFiles['response'] as $file) {
                        if (isset($file['path']) && isset($file['sha'])) {
                            $deleteData = [
                                'message' => 'Hapus ' . $file['name'] . ' via App',
                                'sha' => $file['sha']
                            ];
                            // DELETE file berdasarkan path dan sha
                            requestGitHubApi('DELETE', $settings['github_username'], $settings['github_repo'], $settings['github_token'], $file['path'], $deleteData);
                        }
                    }
                }
            }

            // Hapus dari history.json
            $historyData = array_filter($historyData, function($item) use ($folderToDelete) {
                return $item['folder'] !== $folderToDelete;
            });
            file_put_contents($historyFile, json_encode(array_values($historyData), JSON_PRETTY_PRINT));

            // Hapus folder lokal jika ada (Untuk produk lokal atau jika temp tertinggal)
            if (is_dir($folderToDelete)) {
                $files = array_diff(scandir($folderToDelete), array('.','..'));
                foreach ($files as $file) unlink("$folderToDelete/$file");
                rmdir($folderToDelete);
            }
            
            // Hapus thumbnail lokal jika ada
            $thumbPath = "thumbnails/{$folderToDelete}_thumb.jpg";
            if (file_exists($thumbPath)) unlink($thumbPath);

            echo json_encode(['status' => 'success']);
            exit;
        }
        echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus riwayat.']);
        exit;
    }

    // 3. ACTION: BUAT PRODUK (AJAX DARI RUMAH)
    if ($action === 'create_product') {
        header('Content-Type: application/json');

        $mode = $_POST['mode'] ?? 'view';
        $namaRaw = trim($_POST['nama'] ?? 'Produk');
        $linkEksternal = trim($_POST['linkEksternal'] ?? '#');
        if (empty($linkEksternal)) $linkEksternal = '#';
        
        $harga = trim($_POST['harga'] ?? 'Rp 0');
        $hargaCoret = trim($_POST['hargaCoret'] ?? 'Rp 0');
        $deskripsi = trim($_POST['deskripsi'] ?? '');

        $safeName = preg_replace('/[^a-z0-9]+/', '_', strtolower($namaRaw));
        $safeName = trim($safeName, '_');
        if (empty($safeName)) $safeName = 'produk_untitled';

        $folderName = $safeName . '-' . $mode;
        
        if (!is_dir('thumbnails')) mkdir('thumbnails', 0777, true);
        if (!is_dir($folderName)) mkdir($folderName, 0777, true);

        $uploadedFilesData = [];
        $thumbName = 'https://images.unsplash.com/photo-1583337130417-3346a1be7dee?w=150';

        if (!empty($_FILES['files']['name'][0])) {
            foreach ($_FILES['files']['name'] as $key => $filename) {
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $thumbName = str_replace(' ', '_', $filename); break;
                }
            }
            foreach ($_FILES['files']['name'] as $key => $filename) {
                $tmpName = $_FILES['files']['tmp_name'][$key];
                $cleanFilename = str_replace(' ', '_', $filename);
                $targetPath = $folderName . '/' . $cleanFilename;

                if (move_uploaded_file($tmpName, $targetPath)) {
                    $ext = strtolower(pathinfo($cleanFilename, PATHINFO_EXTENSION));
                    $type = in_array($ext, ['glb', 'gltf']) ? 'glb' : (in_array($ext, ['mp4', 'mkv', 'webm']) ? 'mp4' : 'jpg');
                    $uploadedFilesData[] = [
                        'type' => $type, 'src' => $cleanFilename,
                        'thumb' => ($type === 'jpg') ? $cleanFilename : $thumbName
                    ];
                }
            }
        }

        $jsonData = ['nama' => $namaRaw, 'link' => $linkEksternal, 'files' => $uploadedFilesData];
        if ($mode === 'enterprice') {
            $jsonData['harga'] = $harga; $jsonData['harga_coret'] = $hargaCoret; $jsonData['deskripsi'] = $deskripsi;
        }

        file_put_contents($folderName . "/3d-{$mode}.txt", json_encode($jsonData, JSON_PRETTY_PRINT));

        $htmlContent = ($mode === 'enterprice') ? getEnterpriseTemplate() : getViewTemplate();
        $htmlFileName = $safeName . '-' . $mode . '.html';
        file_put_contents($folderName . '/' . $htmlFileName, $htmlContent);

        $fullUrl = "";
        $historyThumbUrl = "";

        $isGitHubMode = ($settings['connected'] && !empty($settings['github_token']));

        if ($isGitHubMode) {
            $filesToUpload = array_diff(scandir($folderName), ['.', '..']);
            $uploadSuccess = true;
            $errMessage = "";

            foreach ($filesToUpload as $file) {
                $filePath = $folderName . '/' . $file;
                $base64 = base64_encode(file_get_contents($filePath));
                
                $hasilUpload = uploadToGitHub($settings['github_username'], $settings['github_repo'], $settings['github_token'], $folderName . '/' . $file, $base64);
                
                if ($hasilUpload['code'] !== 200 && $hasilUpload['code'] !== 201) {
                    $uploadSuccess = false;
                    $errMessage = isset($hasilUpload['response']['message']) ? $hasilUpload['response']['message'] : $hasilUpload['error'];
                    break;
                }
            }

            if (!$uploadSuccess) {
                foreach ($filesToUpload as $file) unlink($folderName . '/' . $file);
                rmdir($folderName);
                
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Upload ke GitHub ditolak. Detail: ' . $errMessage
                ]);
                exit;
            }

            $fullUrl = "https://{$settings['github_username']}.github.io/{$settings['github_repo']}/{$folderName}/{$htmlFileName}";

            $sourceThumbPath = $folderName . '/' . $thumbName;
            $localThumbPath = "thumbnails/{$folderName}_thumb.jpg";
            
            if (file_exists($sourceThumbPath) && is_file($sourceThumbPath)) {
                copy($sourceThumbPath, $localThumbPath);
                $historyThumbUrl = $localThumbPath;
            } else {
                $historyThumbUrl = 'https://images.unsplash.com/photo-1583337130417-3346a1be7dee?w=150';
            }

            foreach ($filesToUpload as $file) {
                unlink($folderName . '/' . $file);
            }
            rmdir($folderName);

        } else {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $fullUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\') . "/" . $folderName . "/" . $htmlFileName;
            
            $historyThumbUrl = filter_var($thumbName, FILTER_VALIDATE_URL) ? $thumbName : "{$folderName}/{$thumbName}";
        }

        $newHistoryEntry = [
            'folder' => $folderName,
            'nama' => $namaRaw,
            'waktu' => date("d M Y H:i"),
            'link' => $fullUrl,
            'thumb' => $historyThumbUrl,
            'timestamp' => time(),
            'mode' => $isGitHubMode ? 'github' : 'local'
        ];

        array_unshift($historyData, $newHistoryEntry);
        file_put_contents($historyFile, json_encode($historyData, JSON_PRETTY_PRINT));

        echo json_encode(['status' => 'success', 'message' => 'Berhasil membuat halaman!', 'link' => $fullUrl]);
        exit;
    }
}

usort($historyData, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });

// === TEMPLATE VIEW ===
function getViewTemplate() {
return <<<'HTML'
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3D Product Viewer - View Mode</title>
    <script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.3.0/model-viewer.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        :root { --biru: #1877f2; --hijau: #25d366; --hitam: #2b2b36; --putih: #ffffff; --bg-body: #f0f2f5; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--bg-body); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px 10px; }
        .product-card { background: var(--putih); width: 100%; max-width: 480px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); position: relative; display: flex; flex-direction: column; }
        .viewer-container { width: 100%; height: 400px; background: var(--hitam); position: relative; display: flex; justify-content: center; align-items: center; overflow: hidden; border-radius: 20px 20px 0 0; }
        .viewer-container model-viewer, .viewer-container img, .viewer-container video { width: 100%; height: 100%; object-fit: contain; animation: mediaFadeIn 0.6s cubic-bezier(0.25, 1, 0.5, 1) forwards; }
        @keyframes mediaFadeIn { 0% { opacity: 0; transform: scale(0.95); } 100% { opacity: 1; transform: scale(1); } }
        .content-wrapper { padding: 25px; }
        .product-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; position: relative; z-index: 10; }
        .product-name { font-size: 1.4rem; color: var(--hitam); font-weight: 700; flex: 1; padding-right: 15px; line-height: 1.3; }
        .share-wrapper { position: relative; width: 50px; height: 50px; }
        .menu { position: absolute; width: 220px; height: 220px; top: 50%; left: 50%; transform: translate(-50%, -50%); display: flex; align-items: center; justify-content: center; pointer-events: none; }
        .menu.active { pointer-events: auto; }
        .menu .toggle { position: relative; height: 50px; width: 50px; background: var(--putih); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--hitam); font-size: 1.4rem; cursor: pointer; transition: transform 1.25s cubic-bezier(0.25, 1, 0.5, 1), box-shadow 0.4s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.1); pointer-events: auto; z-index: 5; }
        .menu.active .toggle { transform: rotate(360deg); box-shadow: 0 6px 8px rgba(0,0,0,0.15), 0 0 0 2px var(--hitam), 0 0 0 6px var(--putih); }
        .menu li { position: absolute; list-style: none; transition: 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); z-index: 4; }
        .menu.active li { transform: rotate(calc(45deg * var(--i))) translateY(-80px); }
        .menu li a { display: flex; justify-content: center; align-items: center; width: 42px; height: 42px; border-radius: 50%; font-size: 1.2rem; color: var(--clr); background: var(--putih); text-decoration: none; transition: 0.3s ease; box-shadow: 0 3px 8px rgba(0,0,0,0.15); transform: rotate(calc(-45deg * var(--i))) scale(0); opacity: 0; }
        .menu.active li a { transform: rotate(calc(-45deg * var(--i))) scale(1); opacity: 1; }
        .menu li a:hover { color: var(--putih); background: var(--clr); box-shadow: 0 0 15px var(--clr); }
        .thumbnails-container { display: flex; gap: 12px; margin-bottom: 25px; overflow-x: auto; padding-bottom: 5px; scroll-behavior: smooth; }
        .thumbnails-container::-webkit-scrollbar { height: 4px; }
        .thumbnails-container::-webkit-scrollbar-thumb { background: #d1d1d1; border-radius: 10px; }
        .thumb-box { min-width: 65px; height: 65px; border-radius: 10px; cursor: pointer; border: 2px solid transparent; overflow: hidden; position: relative; transition: all 0.3s ease; }
        .thumb-box img { width: 100%; height: 100%; object-fit: cover; }
        .thumb-box::after { content: ''; position: absolute; inset: 0; background: rgba(0,0,0,0.3); transition: 0.3s; }
        .thumb-box.active::after, .thumb-box:hover::after { background: transparent; }
        .thumb-box.active { border-color: var(--biru); transform: translateY(-4px); box-shadow: 0 4px 12px rgba(24, 119, 242, 0.3); }
        .btn-lihat { display: block; width: 100%; padding: 16px; text-align: center; background: var(--biru); color: var(--putih); text-decoration: none; font-size: 1.1rem; font-weight: 700; border-radius: 12px; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(24, 119, 242, 0.3); }
        .btn-lihat:hover { background: var(--hijau); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4); }
        .ar-button { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); color: var(--hitam); border: 1px solid rgba(255, 255, 255, 0.5); padding: 10px 24px; border-radius: 30px; font-size: 0.95rem; font-weight: 700; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 8px; transition: all 0.4s; z-index: 100; }
        .ar-button:hover { background: var(--putih); transform: translateX(-50%) translateY(-4px); box-shadow: 0 8px 25px rgba(0,0,0,0.25); color: var(--biru); }
        .ar-icon { width: 20px; height: 20px; fill: currentColor; }
    </style>
</head>
<body>
    <div class="product-card">
        <div class="viewer-container" id="main-viewer"></div>
        <div class="content-wrapper">
            <div class="product-header">
                <h1 class="product-name" id="prod-name">Memuat Data...</h1>
                <div class="share-wrapper">
                    <div class="menu" id="share-menu">
                        <div class="toggle" id="share-toggle"><ion-icon name="share-social"></ion-icon></div>
                        <li style="--i:0; --clr:#6c757d;"><a href="#" onclick="copyShareLink(event)"><i class="fa-solid fa-link"></i></a></li>
                        <li style="--i:7; --clr:#1877f2;"><a href="#" onclick="shareTo('facebook', event)"><i class="fa-brands fa-facebook-f"></i></a></li>
                        <li style="--i:6; --clr:#25d366;"><a href="#" onclick="shareTo('whatsapp', event)"><i class="fa-brands fa-whatsapp"></i></a></li>
                        <li style="--i:5; --clr:#ea4335;"><a href="https://www.youtube.com" target="_blank"><i class="fa-brands fa-youtube"></i></a></li>
                    </div>
                </div>
            </div>
            <div class="thumbnails-container" id="thumbnails"></div>
            <a href="#" class="btn-lihat" id="prod-link" target="_blank">Lihat Produk</a>
        </div>
    </div>
    <script>
        const toggle = document.getElementById('share-toggle'); const menu = document.getElementById('share-menu');
        toggle.onclick = () => menu.classList.toggle('active');
        function shareTo(platform, e) { e.preventDefault(); const url = encodeURIComponent(window.location.href); const title = encodeURIComponent(document.title); let shareUrl = ''; if (platform === 'facebook') { shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${url}`; } else if (platform === 'whatsapp') { shareUrl = `https://api.whatsapp.com/send?text=${title} - ${url}`; } if (shareUrl) window.open(shareUrl, '_blank', 'width=600,height=400'); }
        function copyShareLink(e) { e.preventDefault(); const url = window.location.href; const icon = e.currentTarget.querySelector('i'); const fallbackCopy = (text) => { const textArea = document.createElement("textarea"); textArea.value = text; textArea.style.position = "fixed"; document.body.appendChild(textArea); textArea.focus(); textArea.select(); try { document.execCommand('copy'); showSuccess(); } catch (err) { alert("Gagal menyalin tautan."); } document.body.removeChild(textArea); }; const showSuccess = () => { icon.className = 'fa-solid fa-check'; setTimeout(() => { icon.className = 'fa-solid fa-link'; }, 2000); }; if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(url).then(showSuccess).catch(() => fallbackCopy(url)); } else { fallbackCopy(url); } }
        async function loadProductData() { try { const response = await fetch('3d-view.txt'); const data = await response.json(); renderUI(data); } catch (error) { document.getElementById('prod-name').innerText = "Gagal Memuat Data"; } }
        function renderUI(data) { document.getElementById('prod-name').innerText = data.nama; document.getElementById('prod-link').href = data.link; document.title = data.nama + " | 3D Viewer"; const thumbContainer = document.getElementById('thumbnails'); thumbContainer.innerHTML = ''; data.files.forEach((file, index) => { const thumb = document.createElement('div'); thumb.className = `thumb-box ${index === 0 ? 'active' : ''}`; thumb.innerHTML = `<img src="${file.thumb}" alt="Thumbnail">`; thumb.onclick = () => { document.querySelectorAll('.thumb-box').forEach(el => el.classList.remove('active')); thumb.classList.add('active'); changeMainViewer(file); }; thumbContainer.appendChild(thumb); }); if (data.files.length > 0) changeMainViewer(data.files[0]); }
        function changeMainViewer(file) { const viewer = document.getElementById('main-viewer'); viewer.innerHTML = ''; setTimeout(() => { if (file.type === 'glb' || file.type === 'gltf') { viewer.innerHTML = `<model-viewer src="${file.src}" ar ar-modes="webxr scene-viewer quick-look" auto-rotate camera-controls shadow-intensity="1"><button slot="ar-button" class="ar-button"><svg class="ar-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 3C4.79086 3 3 4.79086 3 7V10H5V7C5 5.89543 5.89543 5 7 5H10V3H7ZM17 3C19.2091 3 21 4.79086 21 7V10H19V7C19 5.89543 18.1046 5 17 5H14V3H17ZM21 14V17C21 19.2091 19.2091 21 17 21H14V19H17C18.1046 19 19 18.1046 19 17V14H21ZM3 14V17C3 19.2091 4.79086 21 7 21H10V19H7C5.89543 19 5 18.1046 5 17V14H3ZM12 7.5L8.5 9.5V14.5L12 16.5L15.5 14.5V9.5L12 7.5ZM10.5 10.6547L12 9.79904L13.5 10.6547V12.366L12 13.2217L10.5 12.366V10.6547Z"/></svg>Tampilkan AR</button></model-viewer>`; } else if (file.type === 'jpg' || file.type === 'png') { viewer.innerHTML = `<img src="${file.src}" alt="Gambar Produk">`; } else if (file.type === 'mp4' || file.type === 'mkv') { viewer.innerHTML = `<video controls autoplay muted loop><source src="${file.src}" type="video/mp4"></video>`; } }, 30); }
        window.addEventListener('DOMContentLoaded', loadProductData);
    </script>
</body>
</html>
HTML;
}

// === TEMPLATE ENTERPRISE ===
function getEnterpriseTemplate() {
return <<<'HTML'
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Product Viewer</title>
    <script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.3.0/model-viewer.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        :root { --biru: #1877f2; --hijau: #25d366; --hitam: #2b2b36; --putih: #ffffff; --abu-bg: #f4f7f6; --abu-teks: #666666; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--abu-bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px 10px; }
        .enterprise-card { background: var(--putih); width: 100%; max-width: 480px; border-radius: 20px; box-shadow: 0 12px 35px rgba(0,0,0,0.08); display: flex; flex-direction: column; }
        .viewer-box { width: 100%; height: 380px; background: var(--hitam); position: relative; display: flex; justify-content: center; align-items: center; overflow: hidden; border-radius: 20px 20px 0 0; }
        .viewer-box model-viewer, .viewer-box img, .viewer-box video { width: 100%; height: 100%; object-fit: contain; animation: fadeInMedia 0.5s ease-in-out forwards; }
        @keyframes fadeInMedia { from { opacity: 0; transform: scale(0.97); } to { opacity: 1; transform: scale(1); } }
        .content-section { padding: 25px; }
        .price-row { display: flex; align-items: baseline; gap: 12px; margin-bottom: 8px; }
        .harga-coret { color: #a0a0a0; text-decoration: line-through; font-size: 1.1rem; font-weight: 500; }
        .harga-asli { color: var(--hijau); font-size: 1.8rem; font-weight: 800; }
        .title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 35px; position: relative; z-index: 10; }
        .product-title { font-size: 1.4rem; color: var(--hitam); font-weight: 700; line-height: 1.3; flex: 1; padding-right: 15px; }
        .share-container { position: relative; width: 50px; height: 50px; }
        .menu { position: absolute; width: 220px; height: 220px; top: 50%; left: 50%; transform: translate(-50%, -50%); display: flex; align-items: center; justify-content: center; pointer-events: none; }
        .menu.active { pointer-events: auto; }
        .menu .toggle { position: relative; height: 50px; width: 50px; background: var(--putih); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--hitam); font-size: 1.4rem; cursor: pointer; transition: transform 1.25s cubic-bezier(0.25, 1, 0.5, 1), box-shadow 0.4s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.1); pointer-events: auto; }
        .menu.active .toggle { transform: rotate(360deg); box-shadow: 0 6px 8px rgba(0,0,0,0.15), 0 0 0 2px var(--hitam), 0 0 0 6px var(--putih); }
        .menu li { position: absolute; list-style: none; transition: 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .menu.active li { transform: rotate(calc(45deg * var(--i))) translateY(-80px); }
        .menu li a { display: flex; justify-content: center; align-items: center; width: 42px; height: 42px; border-radius: 50%; font-size: 1.2rem; color: var(--clr); background: var(--putih); text-decoration: none; transition: 0.3s ease; box-shadow: 0 3px 8px rgba(0,0,0,0.15); transform: rotate(calc(-45deg * var(--i))) scale(0); opacity: 0; }
        .menu.active li a { transform: rotate(calc(-45deg * var(--i))) scale(1); opacity: 1; }
        .menu li a:hover { color: var(--putih); background: var(--clr); box-shadow: 0 0 15px var(--clr); }
        .thumbnails-box { display: flex; gap: 12px; margin-bottom: 25px; overflow-x: auto; padding-bottom: 5px; scroll-behavior: smooth; }
        .thumbnails-box::-webkit-scrollbar { height: 4px; }
        .thumbnails-box::-webkit-scrollbar-thumb { background: #d1d1d1; border-radius: 10px; }
        .thumb-item { min-width: 65px; height: 65px; border-radius: 10px; cursor: pointer; border: 2px solid transparent; overflow: hidden; position: relative; transition: all 0.3s ease; }
        .thumb-item img { width: 100%; height: 100%; object-fit: cover; }
        .thumb-item::after { content: ''; position: absolute; inset: 0; background: rgba(0,0,0,0.3); transition: 0.3s; }
        .thumb-item:hover::after, .thumb-item.active::after { background: transparent; }
        .thumb-item.active { border-color: var(--biru); transform: translateY(-4px); box-shadow: 0 4px 12px rgba(24, 119, 242, 0.3); }
        .description-box { color: var(--abu-teks); font-size: 0.95rem; line-height: 1.6; margin-bottom: 25px; background: #fafafa; padding: 15px; border-radius: 10px; border-left: 4px solid var(--biru); }
        .btn-lihat { display: block; width: 100%; padding: 16px; text-align: center; background: var(--biru); color: var(--putih); text-decoration: none; font-size: 1.1rem; font-weight: 700; border-radius: 12px; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(24, 119, 242, 0.3); }
        .btn-lihat:hover { background: var(--hijau); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4); }
        .ar-button { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); color: var(--hitam); border: 1px solid rgba(255, 255, 255, 0.5); padding: 10px 24px; border-radius: 30px; font-size: 0.95rem; font-weight: 700; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 8px; transition: all 0.4s; z-index: 100; }
        .ar-button:hover { background: var(--putih); transform: translateX(-50%) translateY(-4px); box-shadow: 0 8px 25px rgba(0,0,0,0.25); color: var(--biru); }
        .ar-icon { width: 20px; height: 20px; fill: currentColor; }
    </style>
</head>
<body>
    <div class="enterprise-card">
        <div class="viewer-box" id="main-viewer"></div>
        <div class="content-section">
            <div class="price-row">
                <span class="harga-coret" id="prod-harga-coret">Rp 0</span>
                <span class="harga-asli" id="prod-harga">Rp 0</span>
            </div>
            <div class="title-row">
                <h1 class="product-title" id="prod-name">Memuat Data...</h1>
                <div class="share-container">
                    <div class="menu" id="share-menu">
                        <div class="toggle" id="share-toggle"><ion-icon name="share-social"></ion-icon></div>
                        <li style="--i:0; --clr:#6c757d;"><a href="#" onclick="copyShareLink(event)"><i class="fa-solid fa-link"></i></a></li>
                        <li style="--i:7; --clr:#0a66c2;"><a href="#" onclick="shareTo('linkedin', event)"><i class="fa-brands fa-linkedin-in"></i></a></li>
                        <li style="--i:6; --clr:#1877f2;"><a href="#" onclick="shareTo('facebook', event)"><i class="fa-brands fa-facebook-f"></i></a></li>
                        <li style="--i:5; --clr:#25d366;"><a href="#" onclick="shareTo('whatsapp', event)"><i class="fa-brands fa-whatsapp"></i></a></li>
                        <li style="--i:4; --clr:#ea4335;"><a href="https://www.youtube.com" target="_blank"><i class="fa-brands fa-youtube"></i></a></li>
                    </div>
                </div>
            </div>
            <div class="thumbnails-box" id="thumbnails"></div>
            <div class="description-box" id="prod-desc"></div>
            <a href="#" class="btn-lihat" id="prod-link" target="_blank">Beli Sekarang</a>
        </div>
    </div>
    <script>
        const toggle = document.getElementById('share-toggle'); const menu = document.getElementById('share-menu');
        toggle.onclick = () => menu.classList.toggle('active');
        function shareTo(platform, e) { e.preventDefault(); const url = encodeURIComponent(window.location.href); const title = encodeURIComponent(document.title); let shareUrl = ''; if (platform === 'facebook') { shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${url}`; } else if (platform === 'whatsapp') { shareUrl = `https://api.whatsapp.com/send?text=${title} - ${url}`; } else if (platform === 'linkedin') { shareUrl = `https://www.linkedin.com/sharing/share-offsite/?url=${url}`; } if (shareUrl) window.open(shareUrl, '_blank', 'width=600,height=400'); }
        function copyShareLink(e) { e.preventDefault(); const url = window.location.href; const icon = e.currentTarget.querySelector('i'); const fallbackCopy = (text) => { const textArea = document.createElement("textarea"); textArea.value = text; textArea.style.position = "fixed"; document.body.appendChild(textArea); textArea.focus(); textArea.select(); try { document.execCommand('copy'); showSuccess(); } catch (err) { alert("Gagal menyalin tautan."); } document.body.removeChild(textArea); }; const showSuccess = () => { icon.className = 'fa-solid fa-check'; setTimeout(() => { icon.className = 'fa-solid fa-link'; }, 2000); }; if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(url).then(showSuccess).catch(() => fallbackCopy(url)); } else { fallbackCopy(url); } }
        async function loadData() { try { const res = await fetch('3d-enterprice.txt'); const data = await res.json(); renderEnterpriseUI(data); } catch (err) { document.getElementById('prod-name').innerText = "Gagal Memuat Data"; } }
        function renderEnterpriseUI(data) { document.getElementById('prod-name').innerText = data.nama; document.getElementById('prod-harga').innerText = data.harga; document.getElementById('prod-harga-coret').innerText = data.harga_coret; document.getElementById('prod-desc').innerText = data.deskripsi; document.getElementById('prod-link').href = data.link; document.title = data.nama + " | Enterprise Viewer"; const thumbBox = document.getElementById('thumbnails'); thumbBox.innerHTML = ''; data.files.forEach((file, idx) => { const thumb = document.createElement('div'); thumb.className = `thumb-item ${idx === 0 ? 'active' : ''}`; thumb.innerHTML = `<img src="${file.thumb}" alt="thumb">`; thumb.onclick = () => { document.querySelectorAll('.thumb-item').forEach(el => el.classList.remove('active')); thumb.classList.add('active'); updateViewer(file); }; thumbBox.appendChild(thumb); }); if (data.files.length > 0) updateViewer(data.files[0]); }
        function updateViewer(file) { const viewer = document.getElementById('main-viewer'); viewer.innerHTML = ''; setTimeout(() => { if (file.type === 'glb' || file.type === 'gltf') { viewer.innerHTML = `<model-viewer src="${file.src}" ar ar-modes="webxr scene-viewer quick-look" auto-rotate camera-controls shadow-intensity="1"><button slot="ar-button" class="ar-button"><svg class="ar-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 3C4.79086 3 3 4.79086 3 7V10H5V7C5 5.89543 5.89543 5 7 5H10V3H7ZM17 3C19.2091 3 21 4.79086 21 7V10H19V7C19 5.89543 18.1046 5 17 5H14V3H17ZM21 14V17C21 19.2091 19.2091 21 17 21H14V19H17C18.1046 19 19 18.1046 19 17V14H21ZM3 14V17C3 19.2091 4.79086 21 7 21H10V19H7C5.89543 19 5 18.1046 5 17V14H3ZM12 7.5L8.5 9.5V14.5L12 16.5L15.5 14.5V9.5L12 7.5ZM10.5 10.6547L12 9.79904L13.5 10.6547V12.366L12 13.2217L10.5 12.366V10.6547Z"/></svg>Tampilkan AR</button></model-viewer>`; } else if (file.type === 'jpg' || file.type === 'png') { viewer.innerHTML = `<img src="${file.src}">`; } else if (file.type === 'mp4' || file.type === 'mkv') { viewer.innerHTML = `<video controls autoplay muted loop><source src="${file.src}" type="video/mp4"></video>`; } }, 30); }
        window.addEventListener('DOMContentLoaded', loadData);
    </script>
</body>
</html>
HTML;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>HAMIDAN STORE 3D</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    
    <style>
        /* === TEMA WARNA & DASAR GLOBAL === */
        :root {
            --biru: #1877f2;
            --biru-gelap: #145dbf;
            --hijau: #25d366;
            --hijau-gelap: #1fae53;
            --putih: #ffffff;
            --abu-bg: #f4f7f6;
            --abu-border: #e1e4e8;
            --abu-teks: #666666;
            --hitam-teks: #2b2b36;
            --merah: #ff4757;
            --transisi: 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        /* === TEMA GELAP (DARK MODE) === */
        body.dark-mode {
            --putih: #1e1e24;
            --abu-bg: #121212;
            --abu-border: #2c2c35;
            --abu-teks: #a0a0a0;
            --hitam-teks: #ffffff;
        }
        body.dark-mode .nav-wrapper .navigation { background: #1e1e24; box-shadow: 0 -5px 25px rgba(0,0,0,0.3); }
        body.dark-mode .nav-wrapper .navigation ul li a .icon, body.dark-mode .nav-wrapper .navigation ul li a .text { color: #ffffff; }
        body.dark-mode .nav-wrapper .indicator { background: #ffffff; border-color: #121212; }
        body.dark-mode .nav-wrapper .navigation ul li.active a .icon { color: #000000; }
        body.dark-mode .nav-wrapper .indicator::before, body.dark-mode .nav-wrapper .indicator::after { box-shadow: 1px -10px 0 0 #121212; }
        body.dark-mode .nav-wrapper .indicator::after { box-shadow: -1px -10px 0 0 #121212; }
        body.dark-mode .form-group input, body.dark-mode .form-group textarea { background-color: #2c2c35; }
        body.dark-mode .upload-box { background-color: #2c2c35; }
        body.dark-mode .history-card { border-color: #2c2c35; }
        body.dark-mode .tutorial-box { background: rgba(24, 119, 242, 0.1); border-color: #2c2c35; }
        body.dark-mode .app-info-box { background: rgba(37, 211, 102, 0.1); border-color: #2c2c35; }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, sans-serif; }

        body {
            background-color: var(--abu-bg); color: var(--hitam-teks);
            display: flex; flex-direction: column; align-items: center;
            min-height: 100vh; padding: 20px 20px 100px 20px;
            transition: background-color var(--transisi), color var(--transisi);
            overflow-x: hidden;
        }

        /* === SPA PAGE TRANSITIONS === */
        .page-content {
            display: none; width: 100%; max-width: 500px;
            flex-direction: column; animation: fadeInPage 0.5s ease forwards;
        }
        .page-content.active { display: flex; }
        @keyframes fadeInPage { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }

        /* === KOMPONEN UMUM CONTAINER === */
        .container {
            background-color: var(--putih); border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.05); padding: 35px; margin-bottom: 20px;
            transition: background-color var(--transisi), box-shadow var(--transisi); width: 100%;
        }
        .page-title { font-size: 1.5rem; font-weight: 800; color: var(--hitam-teks); margin-bottom: 20px; }
        .logo-container { text-align: center; margin-bottom: 30px; }
        .logo-container img { width: 120px; height: 120px; object-fit: contain; border-radius: 15px; transition: transform var(--transisi); }
        .logo-container img:hover { transform: scale(1.05); }

        /* === STYLING KHUSUS RUMAH (FORM) === */
        .top-bar-rumah { display: flex; justify-content: flex-end; align-items: center; margin-bottom: 25px; }
        .mode-switch { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 700; }
        .mode-label { transition: color var(--transisi); color: var(--abu-teks); }
        .mode-label.active { color: var(--biru); }
        .switch { position: relative; display: inline-block; width: 48px; height: 26px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--abu-border); transition: var(--transisi); border-radius: 30px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 4px; bottom: 4px; background-color: var(--putih); transition: var(--transisi); border-radius: 50%; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        input:checked + .slider { background-color: var(--hijau); }
        input:checked + .slider.theme-slider { background-color: var(--hitam-teks); }
        input:checked + .slider:before { transform: translateX(22px); }

        .form-group { margin-bottom: 18px; position: relative; }
        input[type="text"], input[type="password"], input[type="number"], textarea {
            width: 100%; padding: 15px 18px; border: 2px solid var(--abu-border);
            border-radius: 12px; background-color: #fafafa; font-size: 15px; color: var(--hitam-teks);
            transition: var(--transisi); outline: none;
        }
        input:focus, textarea:focus { background-color: var(--putih); border-color: var(--biru); box-shadow: 0 0 0 4px rgba(24, 119, 242, 0.1); }
        textarea { resize: vertical; min-height: 100px; }
        
        .upload-section { margin-bottom: 20px; }
        .upload-grid { display: flex; flex-wrap: wrap; gap: 15px; }
        .upload-wrapper { display: flex; flex-direction: column; gap: 6px; width: 75px; }
        .upload-box {
            width: 75px; height: 75px; border: 2px dashed var(--abu-border); border-radius: 12px;
            background-color: #fafafa; display: flex; flex-direction: column; justify-content: center; align-items: center;
            cursor: pointer; position: relative; overflow: hidden; transition: var(--transisi); color: var(--abu-teks);
        }
        .upload-box.empty:hover { border-color: var(--biru); color: var(--biru); background-color: rgba(24,119,242,0.05); }
        .upload-box .plus-icon { font-size: 28px; font-weight: 300; }
        .upload-box.filled { border: 2px solid var(--biru); background-color: var(--putih); cursor: default; }
        .upload-content { position: relative; z-index: 2; text-align: center; width: 100%; transition: opacity 0.3s; }
        .file-ext { font-size: 14px; font-weight: 800; color: var(--biru); text-transform: uppercase; }
        .btn-remove { position: absolute; top: -5px; right: -5px; background: var(--biru); border: none; color: var(--putih); font-size: 12px; font-weight: bold; cursor: pointer; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 5; transition: var(--transisi); }
        .btn-remove:hover { background: var(--biru-gelap); transform: scale(1.1); }
        
        .progress-overlay { position: absolute; bottom: 0; left: 0; width: 100%; height: 0%; background: rgba(37, 211, 102, 0.15); z-index: 1; transition: height 0.4s ease; }
        .progress-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 15px; font-weight: 800; color: var(--hijau); z-index: 4; display: none; }
        .progress-bar-bottom { width: 100%; height: 6px; background: var(--abu-border); border-radius: 3px; overflow: hidden; display: none; }
        .progress-fill { height: 100%; width: 0%; background: var(--hijau); transition: width 0.4s ease; }

        .enterprise-fields { display: grid; grid-template-rows: 0fr; transition: grid-template-rows var(--transisi); }
        .enterprise-inner { overflow: hidden; opacity: 0; transition: opacity var(--transisi); }
        .enterprise-mode .enterprise-fields { grid-template-rows: 1fr; }
        .enterprise-mode .enterprise-inner { padding-top: 10px; opacity: 1; }

        .btn-submit { width: 100%; background-color: var(--biru); color: var(--putih); border: none; padding: 16px; border-radius: 12px; font-size: 1.1rem; font-weight: 700; cursor: pointer; transition: var(--transisi); margin-top: 10px; display: flex; justify-content: center; align-items: center; gap: 8px;}
        .btn-submit:hover { background-color: var(--biru-gelap); transform: translateY(-3px); box-shadow: 0 8px 20px rgba(24, 119, 242, 0.3); }
        .btn-submit:disabled { background-color: var(--abu-border); color: var(--abu-teks); cursor: not-allowed; transform: none; box-shadow: none; }

        /* === MODAL POPUP (RUMAH & QR) === */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(5px); display: flex; justify-content: center; align-items: center; opacity: 0; visibility: hidden; transition: var(--transisi); z-index: 2000; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-content { background: var(--putih); padding: 35px 30px; border-radius: 20px; width: 90%; max-width: 380px; text-align: center; transform: translateY(30px) scale(0.95); transition: var(--transisi); box-shadow: 0 20px 50px rgba(0,0,0,0.15); border: 1px solid var(--abu-border); }
        .modal-overlay.active .modal-content { transform: translateY(0) scale(1); }
        .modal-content h3 { color: var(--hijau); margin-bottom: 15px; font-size: 1.5rem; font-weight: 800; }
        .link-result { background: var(--abu-bg); padding: 12px; border-radius: 8px; font-size: 0.9rem; color: var(--abu-teks); margin-bottom: 25px; word-break: break-all; border: 1px solid var(--abu-border); }
        .modal-btn { display: block; width: 100%; padding: 15px; margin-bottom: 12px; border-radius: 12px; font-weight: 700; cursor: pointer; transition: var(--transisi); font-size: 1rem; border: none; }
        .btn-copy { background: var(--biru); color: var(--putih); }
        .btn-copy:hover { background: var(--biru-gelap); box-shadow: 0 5px 15px rgba(24, 119, 242, 0.3); }
        .btn-done { background: var(--hijau); color: var(--putih); }
        .btn-done:hover { background: var(--hijau-gelap); box-shadow: 0 5px 15px rgba(37, 211, 102, 0.3); }
        .qr-image { width: 200px; height: 200px; margin: 0 auto 20px auto; display: block; border-radius: 10px; border: 1px solid var(--abu-border); padding: 10px; background: #ffffff; }

        /* === STYLING DEVELOPER === */
        .dev-content h2 { color: var(--hitam-teks); font-size: 1.6rem; font-weight: 800; margin-bottom: 15px; text-align: left; border-bottom: 2px solid var(--abu-bg); padding-bottom: 10px; }
        .dev-content p { color: var(--abu-teks); font-size: 1rem; line-height: 1.6; margin-bottom: 15px; text-align: justify; }
        .dev-content strong { color: var(--hitam-teks); }
        .fitur-list { margin-top: 15px; margin-bottom: 20px; padding-left: 20px; }
        .fitur-list li { color: var(--abu-teks); font-size: 0.95rem; line-height: 1.6; margin-bottom: 10px; text-align: justify; }
        .contact-box { background: rgba(24, 119, 242, 0.05); border: 1px solid var(--abu-border); border-radius: 12px; padding: 20px; margin-top: 25px; border-left: 5px solid var(--biru); }
        .contact-box h3 { color: var(--hitam-teks); font-size: 1.1rem; font-weight: 700; margin-bottom: 10px; }
        .contact-box p { margin-bottom: 5px; font-size: 0.95rem; color: var(--abu-teks); text-align: left; display: flex; align-items: center; gap: 10px; }
        .contact-box p i { color: var(--biru); width: 20px; text-align: center; }

        .app-info-box { background: rgba(37, 211, 102, 0.05); border: 1px solid var(--abu-border); border-radius: 12px; padding: 20px; margin-bottom: 25px; border-left: 5px solid var(--hijau); }
        .app-info-box h3 { color: var(--hitam-teks); font-size: 1.1rem; font-weight: 700; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        .app-info-box p { color: var(--abu-teks); font-size: 0.95rem; line-height: 1.6; margin-bottom: 8px; display: flex; justify-content: space-between; border-bottom: 1px dashed var(--abu-border); padding-bottom: 5px; }
        .app-info-box p:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .app-info-box strong { color: var(--hitam-teks); }

        /* === STYLING RIWAYAT === */
        .history-card { background-color: var(--putih); border-radius: 15px; padding: 15px; display: flex; align-items: center; gap: 15px; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.04); position: relative; transition: var(--transisi); border: 1px solid transparent; margin-bottom: 15px; }
        .history-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08); border-color: rgba(24, 119, 242, 0.2); }
        .history-thumb { width: 80px; height: 80px; border-radius: 10px; object-fit: cover; background-color: var(--abu-bg); flex-shrink: 0; border: 1px solid var(--abu-border); }
        .history-info { flex: 1; display: flex; flex-direction: column; justify-content: center; overflow: hidden; }
        .history-info h3 { font-size: 1.1rem; font-weight: 700; color: var(--hitam-teks); margin-bottom: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-right: 25px; }
        .history-info p { font-size: 0.85rem; color: var(--abu-teks); margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
        .history-actions { display: flex; gap: 8px; align-items: center; margin-top: auto; }
        .history-link { font-size: 0.85rem; color: var(--biru); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; background: rgba(24, 119, 242, 0.1); padding: 5px 10px; border-radius: 6px; align-self: flex-start; transition: var(--transisi); }
        .history-link:hover { background: var(--biru); color: var(--putih); }
        .btn-qr { background: rgba(37, 211, 102, 0.1); border: none; color: var(--hijau); font-size: 1rem; cursor: pointer; transition: var(--transisi); display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; }
        .btn-qr:hover { color: var(--putih); background: var(--hijau); }
        .btn-delete { position: absolute; top: 15px; right: 15px; background: transparent; border: none; color: #b0b0b0; font-size: 1.2rem; cursor: pointer; transition: var(--transisi); display: flex; align-items: center; justify-content: center; width: 25px; height: 25px; border-radius: 50%; }
        .btn-delete:hover { color: var(--merah); background: rgba(255, 71, 87, 0.1); }
        .empty-state { text-align: center; padding: 40px 20px; background: var(--putih); border-radius: 15px; color: var(--abu-teks); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.04); }
        .empty-state i { font-size: 3rem; color: var(--abu-border); margin-bottom: 15px; }

        /* === STYLING PENGATURAN === */
        .top-bar-pengaturan { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid var(--abu-bg); padding-bottom: 15px; }
        .section-title { font-size: 1.2rem; font-weight: 700; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
        .status-badge { font-size: 0.8rem; padding: 5px 12px; border-radius: 20px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .status-connected { background: rgba(37, 211, 102, 0.15); color: var(--hijau); }
        .status-disconnected { background: rgba(255, 71, 87, 0.15); color: var(--merah); }
        
        /* Tutorial Box */
        .tutorial-box { background: rgba(24, 119, 242, 0.05); border: 1px solid var(--abu-border); border-radius: 12px; padding: 20px; margin-top: 30px; border-left: 5px solid var(--biru); }
        .tutorial-box h3 { color: var(--hitam-teks); font-size: 1.1rem; font-weight: 700; margin-bottom: 15px; }
        .tutorial-box h4 { color: var(--hitam-teks); font-size: 1rem; font-weight: 600; margin-bottom: 8px; margin-top: 20px; }
        .tutorial-box p { color: var(--abu-teks); font-size: 0.95rem; line-height: 1.6; margin-bottom: 10px; }
        .tutorial-box ol { padding-left: 20px; color: var(--abu-teks); font-size: 0.95rem; line-height: 1.6; }
        .tutorial-box li { margin-bottom: 8px; }
        .tutorial-box strong { color: var(--hitam-teks); }

        /* === FIXED NAVIGATION BAWAH === */
        .nav-wrapper { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: 500px; z-index: 1000; display: flex; justify-content: center; }
        .navigation { position: relative; width: 100%; height: 70px; background: var(--putih); display: flex; justify-content: center; align-items: center; border-radius: 20px 20px 0 0; box-shadow: 0 -5px 25px rgba(0, 0, 0, 0.05); transition: background var(--transisi); }
        .navigation ul { display: flex; width: 280px; }
        .navigation ul li { position: relative; list-style: none; width: 70px; height: 70px; z-index: 1; }
        .navigation ul li a { position: relative; display: flex; justify-content: center; align-items: center; flex-direction: column; width: 100%; text-align: center; font-weight: 500; text-decoration: none; cursor: pointer; }
        .navigation ul li a .icon { position: relative; display: block; line-height: 75px; font-size: 1.5em; text-align: center; color: var(--hitam-teks); transition: 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55); }
        .navigation ul li.active a .icon { transform: translateY(-32px); color: var(--putih); z-index: 10; }
        .navigation ul li a .text { position: absolute; color: var(--hitam-teks); font-weight: 600; font-size: 0.75em; letter-spacing: 0.05em; transition: 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55); opacity: 0; transform: translateY(20px); }
        .navigation ul li.active a .text { opacity: 1; transform: translateY(10px); }
        .indicator { position: absolute; top: -50%; width: 70px; height: 70px; background: var(--hitam-teks); border-radius: 50%; border: 6px solid var(--abu-bg); transition: 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55); }
        .indicator::before { content: ''; position: absolute; top: 50%; left: -22px; width: 20px; height: 20px; background: transparent; border-top-right-radius: 20px; box-shadow: 1px -10px 0 0 var(--abu-bg); transition: box-shadow var(--transisi); }
        .indicator::after { content: ''; position: absolute; top: 50%; right: -22px; width: 20px; height: 20px; background: transparent; border-top-left-radius: 20px; box-shadow: -1px -10px 0 0 var(--abu-bg); transition: box-shadow var(--transisi); }

        .navigation ul li:nth-child(1).active ~ .indicator { transform: translateX(calc(70px * 0)); }
        .navigation ul li:nth-child(2).active ~ .indicator { transform: translateX(calc(70px * 1)); }
        .navigation ul li:nth-child(3).active ~ .indicator { transform: translateX(calc(70px * 2)); }
        .navigation ul li:nth-child(4).active ~ .indicator { transform: translateX(calc(70px * 3)); }

        @media (max-width: 480px) {
            body { padding: 0 0 90px 0; }
            .container { max-width: 100%; border-radius: 0; box-shadow: none; min-height: calc(100vh - 70px); padding: 25px 20px; margin-bottom: 0; }
            .nav-wrapper { max-width: 100%; }
        }
    </style>
</head>
<body>

<div id="page-rumah" class="page-content active">
    <div class="container" id="containerRumah">
        <div class="top-bar-rumah">
            <div class="mode-switch">
                <span id="labelView" class="mode-label active">VIEW</span>
                <label class="switch">
                    <input type="checkbox" id="modeToggle">
                    <span class="slider"></span>
                </label>
                <span id="labelEnterprise" class="mode-label">ENTERPRICE</span>
            </div>
        </div>

        <div class="logo-container">
            <img src="logo.png" alt="Logo" onerror="this.src='https://via.placeholder.com/120?text=Logo'">
        </div>

        <form id="productForm">
            <div class="form-group">
                <input type="text" id="nama" name="nama" placeholder="Nama Produk" required>
            </div>

            <div class="upload-section">
                <input type="file" id="fileInput" multiple accept=".jpg,.png,.glb,.gltf,.mp4" style="display: none;">
                <div class="upload-grid" id="uploadGrid"></div>
                <small style="color: var(--abu-teks); font-size: 12px; margin-top: 10px; display: block; font-weight: 500;">Upload file (Max 6: JPG, PNG, GLB, GLF, MP4)</small>
            </div>

            <div class="form-group">
                <input type="text" id="linkEksternal" name="linkEksternal" placeholder="Link Eksternal (Opsional)">
            </div>

            <div class="enterprise-fields">
                <div class="enterprise-inner">
                    <div class="form-group">
                        <input type="text" id="harga" name="harga" placeholder="Harga Jual (Cth: Rp 25.000)">
                    </div>
                    <div class="form-group">
                        <input type="text" id="hargaCoret" name="hargaCoret" placeholder="Harga Coret (Cth: Rp 30.000)">
                    </div>
                    <div class="form-group">
                        <textarea id="deskripsi" name="deskripsi" placeholder="Tuliskan Deskripsi Lengkap Produk..."></textarea>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="btnSubmit">Buat Sekarang</button>
        </form>
    </div>
</div>

<div id="page-developer" class="page-content">
    <div class="container">
        <div class="logo-container">
            <img src="logo.png" alt="Logo Hamidan Store" onerror="this.src='https://via.placeholder.com/120?text=Logo'">
        </div>
        <div class="dev-content">
            <div class="app-info-box">
                <h3><i class="fa-solid fa-circle-info" style="color: var(--hijau);"></i> Detail Aplikasi</h3>
                <p><strong>Nama Aplikasi:</strong> <span>3dAR</span></p>
                <p><strong>Versi:</strong> <span>1.0</span></p>
                <p><strong>Platform:</strong> <span> (PHP, HTML, JS, CSS)</span></p>
                <p><strong>Lisensi:</strong> <span>Hak Cipta &copy; Hamidan Store</span></p>
            </div>

            <h2>Tentang Pengembang</h2>
            <p>Aplikasi ini dikembangkan oleh <strong>Hamidan</strong> di bawah naungan <strong>Hamidan Store</strong>. Kami berdedikasi untuk memberikan solusi digital praktis bagi para pelaku usaha.</p>
            <p>Aplikasi ini dirancang khusus untuk mempermudah pembuatan dan presentasi produk interaktif 3D secara otomatis dengan fitur unggulan:</p>
            <ol class="fitur-list">
                <li><strong>Generate Web Instan:</strong> Membuat halaman viewer produk 3D otomatis dengan unggah file tanpa koding.</li>
                <li><strong>Dukungan Multi-Format & AR:</strong> Menampilkan format media (GLB, JPG, PNG, MP4) yang terintegrasi langsung dengan AR.</li>
                <li><strong>Dual Mode:</strong> Mode <em>View</em> untuk presentasi kasual dan mode <em>Enterprise</em> dengan tautan pembelian.</li>
            </ol>
            <div class="contact-box">
                <h3>Kontak & Dukungan:</h3>
                <p><i class="fa-solid fa-user"></i> <strong>Developer:</strong> Hamidan</p>
                <p><i class="fa-solid fa-envelope"></i> <strong>Email:</strong> hamidanstore@gmail.com</p>
                <p><i class="fa-brands fa-whatsapp"></i> <strong>WhatsApp:</strong> 0838-4111-1463</p>
            </div>
        </div>
    </div>
</div>

<div id="page-riwayat" class="page-content">
    <div style="width: 100%;">
        <h1 class="page-title" style="padding-left: 5px;">Riwayat</h1>
        <div id="historyList">
            <?php if(empty($historyData)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-folder-open"></i>
                    <p>Belum ada riwayat pembuatan produk.</p>
                </div>
            <?php else: ?>
                <?php foreach($historyData as $item): ?>
                    <div class="history-card" id="card-<?= htmlspecialchars($item['folder']) ?>">
                        <img src="<?= htmlspecialchars($item['thumb']) ?>" alt="Thumbnail" class="history-thumb">
                        <div class="history-info">
                            <h3><?= htmlspecialchars($item['nama']) ?></h3>
                            <p>
                                <i class="fa-regular fa-clock"></i> <?= $item['waktu'] ?> 
                                (<?= isset($item['mode']) && $item['mode'] === 'github' ? 'GitHub' : 'Lokal' ?>)
                            </p>
                            <div class="history-actions">
                                <a href="<?= htmlspecialchars($item['link']) ?>" target="_blank" class="history-link">
                                    <i class="fa-solid fa-link"></i> Buka Tautan
                                </a>
                                <button class="btn-qr" onclick="showQrCode('<?= htmlspecialchars($item['link']) ?>')" title="Tampilkan QR Code">
                                    <i class="fa-solid fa-qrcode"></i>
                                </button>
                            </div>
                        </div>
                        <button class="btn-delete" onclick="deleteHistory('<?= htmlspecialchars($item['folder']) ?>')" title="Hapus Riwayat">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="page-pengaturan" class="page-content">
    <div class="container">
        <div class="top-bar-pengaturan">
            <h1 class="page-title" style="margin-bottom:0;">Pengaturan</h1>
            <div class="mode-switch">
                <span id="labelTerang" class="mode-label active">Terang</span>
                <label class="switch">
                    <input type="checkbox" id="themeToggle" class="theme-slider">
                    <span class="slider"></span>
                </label>
                <span id="labelGelap" class="mode-label">Gelap</span>
            </div>
        </div>

        <div class="settings-content">
            <h2 class="section-title">
                Integrasi GitHub Pages
                <?php if ($settings['connected']): ?>
                    <span class="status-badge status-connected"><i class="fa-solid fa-check-circle"></i> Terkoneksi</span>
                <?php else: ?>
                    <span class="status-badge status-disconnected"><i class="fa-solid fa-circle-xmark"></i> Terputus</span>
                <?php endif; ?>
            </h2>

            <form action="" method="POST">
                <input type="hidden" name="action" value="save_github">

                <div class="form-group">
                    <input type="text" name="github_username" placeholder="Username GitHub" value="<?= htmlspecialchars($settings['github_username']) ?>" required>
                </div>
                
                <div class="form-group">
                    <input type="text" name="github_repo" placeholder="Nama Repository" value="<?= htmlspecialchars($settings['github_repo']) ?>" required>
                </div>
                
                <div class="form-group">
                    <input type="password" name="github_token" placeholder="Personal Access Token (PAT)" value="<?= htmlspecialchars($settings['github_token']) ?>" required>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-link"></i> Hubungkan
                </button>
            </form>

            <div class="tutorial-box">
                <h3>Langkah-Langkah Menyiapkan GitHub untuk AR Hosting</h3>
                
                <h4>Langkah 1: Buat Akun & Repository Baru</h4>
                <ol>
                    <li>Buka github.com dan buat akun gratis jika belum punya.</li>
                    <li>Setelah login, klik tombol "New" (berwarna hijau) di sebelah kiri untuk membuat Repository baru.</li>
                    <li>Beri nama repository (contoh: katalog-ar).</li>
                    <li>Pastikan kamu memilih mode <strong>Public</strong> (Sangat penting agar file 3D bisa dibaca oleh browser HP).</li>
                    <li>Centang opsi "Add a README file", lalu klik Create repository.</li>
                </ol>

                <h4>Langkah 2: Aktifkan GitHub Pages (Web Hostingnya)</h4>
                <ol>
                    <li>Di dalam repository <em>katalog-ar</em> yang baru dibuat, klik tab "Settings" (ikon gerigi di atas).</li>
                    <li>Di menu sebelah kiri, cari dan klik menu "Pages".</li>
                    <li>Pada bagian <em>Build and deployment</em> -> <em>Branch</em>, ubah tulisan "None" menjadi "main".</li>
                    <li>Klik Save.</li>
                    <li>Tunggu sekitar 1-2 menit. Jika kamu me-refresh halaman itu, akan muncul tulisan "Your site is live at https://[username-mu].github.io/katalog-ar/". Ini akan menjadi dasar link produkmu nanti!</li>
                </ol>

                <h4>Langkah 3: Buat Token Rahasia (Personal Access Token)</h4>
                <p>Token ini adalah "kunci" agar koding PHP-mu bisa mengunggah file otomatis ke GitHub tanpa perlu login manual.</p>
                <ol>
                    <li>Klik foto profilmu di pojok kanan atas GitHub, lalu pilih "Settings".</li>
                    <li>Scroll ke paling bawah di menu kiri, klik "Developer settings".</li>
                    <li>Klik "Personal access tokens", lalu pilih "Tokens (classic)".</li>
                    <li>Klik tombol "Generate new token (classic)" di kanan atas.</li>
                    <li>Di kolom Note, isi dengan nama aplikasimu (misal: "Aplikasi AR Hamidan").</li>
                    <li>Di bagian Expiration, ubah menjadi "No expiration" agar tokennya tidak kadaluarsa.</li>
                    <li>Di bagian <em>Select scopes</em> (kotak-kotak centang), centang kotak <strong>repo</strong> (ini memberikan akses penuh untuk membuat dan menghapus file).</li>
                    <li>Scroll ke bawah dan klik "Generate token".</li>
                    <li><strong>PENTING:</strong> Akan muncul deretan kode panjang yang diawali dengan <code>ghp_...</code>. Salin dan simpan kode ini di tempat yang aman (misal di Notepad/WhatsApp). Kode ini hanya ditampilkan sekali! Jika hilang, kamu harus membuat yang baru.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="nav-wrapper">
    <div class="navigation">
        <ul>
            <li class="list active" onclick="switchPage('page-rumah', this)">
                <a>
                    <span class="icon"><ion-icon name="home-outline"></ion-icon></span>
                    <span class="text">Rumah</span>
                </a>
            </li>
            <li class="list" onclick="switchPage('page-developer', this)">
                <a>
                    <span class="icon"><ion-icon name="person-outline"></ion-icon></span>
                    <span class="text">Developer</span>
                </a>
            </li>
            <li class="list" onclick="switchPage('page-riwayat', this)">
                <a>
                    <span class="icon"><ion-icon name="chatbubble-outline"></ion-icon></span>
                    <span class="text">Riwayat</span>
                </a>
            </li>
            <li class="list" onclick="switchPage('page-pengaturan', this)">
                <a>
                    <span class="icon"><ion-icon name="settings-outline"></ion-icon></span>
                    <span class="text">Pengaturan</span>
                </a>
            </li>
            <div class="indicator"></div>
        </ul>
    </div>
</div>

<div class="modal-overlay" id="popupModal">
    <div class="modal-content">
        <h3>Berhasil Dibuat!</h3>
        <p style="color: var(--abu-teks); font-size: 14px; margin-bottom: 10px;">HAMIDAN Store: produk dan folder telah siap.</p>
        <div class="link-result" id="resultLinkText">memproses link...</div>
        <button class="modal-btn btn-copy" id="btnSalinLink">Salin Link</button>
        <button class="modal-btn btn-done" id="btnSelesai">Selesai</button>
    </div>
</div>

<div class="modal-overlay" id="qrModal">
    <div class="modal-content">
        <h3>3dAR QR CODE</h3>
        <img src="" id="qrImage" class="qr-image" alt="QR Code">
        <button class="modal-btn btn-done" onclick="closeQrModal()">Tutup</button>
    </div>
</div>

<script>
    // === 1. ROUTING HALAMAN (SPA) ===
    function switchPage(pageId, navItem) {
        document.querySelectorAll('.page-content').forEach(page => page.classList.remove('active'));
        document.querySelectorAll('.list').forEach(li => li.classList.remove('active'));
        
        document.getElementById(pageId).classList.add('active');
        if(navItem) navItem.classList.add('active');
        
        localStorage.setItem('activePage', pageId);
    }

    // Inisialisasi halaman yang aktif saat load
    document.addEventListener('DOMContentLoaded', () => {
        const savedPage = localStorage.getItem('activePage') || 'page-rumah';
        const pageMap = {
            'page-rumah': 0, 'page-developer': 1, 'page-riwayat': 2, 'page-pengaturan': 3
        };
        const index = pageMap[savedPage] !== undefined ? pageMap[savedPage] : 0;
        const navItems = document.querySelectorAll('.list');
        switchPage(savedPage, navItems[index]);

        // Cek Tema
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
            document.getElementById('themeToggle').checked = true;
            updateThemeLabel();
        }
    });


    // === 2. LOGIKA RUMAH (FORM UPLOAD & MODE) ===
    const modeToggle = document.getElementById('modeToggle');
    const containerRumah = document.getElementById('containerRumah');
    const labelView = document.getElementById('labelView');
    const labelEnterprise = document.getElementById('labelEnterprise');

    modeToggle.addEventListener('change', function() {
        if(this.checked) {
            containerRumah.classList.add('enterprise-mode');
            labelEnterprise.classList.add('active'); labelView.classList.remove('active');
        } else {
            containerRumah.classList.remove('enterprise-mode');
            labelView.classList.add('active'); labelEnterprise.classList.remove('active');
        }
    });

    const maxFiles = 6;
    let queuedFiles = []; 
    const uploadGrid = document.getElementById('uploadGrid');
    const fileInput = document.getElementById('fileInput');

    function renderGrid() {
        uploadGrid.innerHTML = '';
        queuedFiles.forEach((file, index) => {
            const wrapper = document.createElement('div'); wrapper.className = 'upload-wrapper';
            const ext = file.name.split('.').pop().substring(0, 4);
            wrapper.innerHTML = `
                <div class="upload-box filled" id="box-${index}">
                    <button type="button" class="btn-remove" id="btn-rm-${index}" onclick="removeFile(${index})"><i class="fa-solid fa-xmark"></i></button>
                    <div class="upload-content" id="content-${index}"><span class="file-ext">${ext}</span></div>
                    <div class="progress-overlay" id="prog-overlay-${index}"></div>
                    <span class="progress-text" id="prog-txt-${index}">0%</span>
                </div>
                <div class="progress-bar-bottom" id="prog-bar-container-${index}"><div class="progress-fill" id="prog-fill-${index}"></div></div>
            `;
            uploadGrid.appendChild(wrapper);
        });

        if (queuedFiles.length < maxFiles) {
            const emptyBox = document.createElement('div'); emptyBox.className = 'upload-box empty';
            emptyBox.innerHTML = `<span class="plus-icon" style="color: var(--biru);">+</span>`;
            emptyBox.onclick = () => fileInput.click();
            uploadGrid.appendChild(emptyBox);
        }
    }

    fileInput.addEventListener('change', function() {
        Array.from(this.files).forEach(f => { if (queuedFiles.length < maxFiles) queuedFiles.push(f); });
        renderGrid(); fileInput.value = ''; 
    });

    window.removeFile = function(index) { queuedFiles.splice(index, 1); renderGrid(); };
    renderGrid();

    // AJAX Form Submit (RUMAH)
    const productForm = document.getElementById('productForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const popupModal = document.getElementById('popupModal');
    let generatedUrl = '';

    productForm.addEventListener('submit', function(e) {
        e.preventDefault();
        if (queuedFiles.length === 0) { alert("Harap upload minimal 1 file gambar/3D!"); return; }

        btnSubmit.innerText = "Mengunggah..."; btnSubmit.disabled = true;
        queuedFiles.forEach((_, index) => {
            document.getElementById(`btn-rm-${index}`).style.display = 'none';
            document.getElementById(`content-${index}`).style.opacity = '0';
            document.getElementById(`prog-txt-${index}`).style.display = 'block';
            document.getElementById(`prog-bar-container-${index}`).style.display = 'block';
            document.getElementById(`box-${index}`).style.borderColor = 'var(--hijau)';
        });

        const formData = new FormData();
        formData.append('action', 'create_product');
        formData.append('mode', modeToggle.checked ? 'enterprice' : 'view');
        formData.append('nama', document.getElementById('nama').value);
        formData.append('linkEksternal', document.getElementById('linkEksternal').value);
        
        if (modeToggle.checked) {
            formData.append('harga', document.getElementById('harga').value);
            formData.append('hargaCoret', document.getElementById('hargaCoret').value);
            formData.append('deskripsi', document.getElementById('deskripsi').value);
        }
        queuedFiles.forEach(file => formData.append('files[]', file));

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'index.php', true);
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                let percentComplete = Math.round((e.loaded / e.total) * 100);
                queuedFiles.forEach((_, index) => {
                    document.getElementById(`prog-fill-${index}`).style.width = percentComplete + '%';
                    document.getElementById(`prog-txt-${index}`).innerText = percentComplete + '%';
                    document.getElementById(`prog-overlay-${index}`).style.height = percentComplete + '%';
                });
                if (percentComplete === 100) btnSubmit.innerText = "Memproses Data...";
            }
        };

        xhr.onload = function() {
            btnSubmit.innerText = "Buat Sekarang"; btnSubmit.disabled = false;
            if (xhr.status === 200) {
                try {
                    const result = JSON.parse(xhr.responseText);
                    if (result.status === 'success') {
                        generatedUrl = result.link;
                        document.getElementById('resultLinkText').innerText = generatedUrl;
                        popupModal.classList.add('active');
                    } else { alert("Error: " + result.message); renderGrid(); }
                } catch (e) { alert("Gagal memproses respons server. Respons:\n" + xhr.responseText); renderGrid(); }
            } else { alert("Gagal terhubung ke server."); renderGrid(); }
        };
        xhr.send(formData);
    });

    document.getElementById('btnSalinLink').addEventListener('click', function() {
        const btn = this; const origText = btn.innerText;
        navigator.clipboard.writeText(generatedUrl).then(() => {
            btn.innerText = "Tersalin!"; btn.style.background = "var(--hijau)";
            setTimeout(() => { btn.innerText = origText; btn.style.background = "var(--biru)"; }, 2000);
        });
    });

    document.getElementById('btnSelesai').addEventListener('click', function() {
        popupModal.classList.remove('active');
        location.reload(); // Reload untuk memperbarui daftar riwayat PHP
    });


    // === 3. LOGIKA RIWAYAT ===
    window.deleteHistory = function(folderName) {
        if (confirm('Yakin menghapus ini dari riwayat? File lokal maupun GitHub (jika terkoneksi) akan dihapus.')) {
            const formData = new FormData();
            formData.append('action', 'delete_history');
            formData.append('folder', folderName);

            fetch('index.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const card = document.getElementById('card-' + folderName);
                    card.style.opacity = '0'; card.style.transform = 'translateY(10px)';
                    setTimeout(() => { 
                        card.remove(); 
                        if (document.querySelectorAll('.history-card').length === 0) location.reload();
                    }, 300);
                } else alert('Gagal: ' + data.message);
            }).catch(e => alert('Terjadi kesalahan server.'));
        }
    };
    
    // Tampilkan QR Code Modal
    function showQrCode(link) {
        document.getElementById('qrImage').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(link);
        document.getElementById('qrModal').classList.add('active');
    }

    // Tutup QR Code Modal
    function closeQrModal() {
        document.getElementById('qrModal').classList.remove('active');
    }


    // === 4. LOGIKA PENGATURAN ===
    const themeToggle = document.getElementById('themeToggle');
    const labelTerang = document.getElementById('labelTerang');
    const labelGelap = document.getElementById('labelGelap');

    themeToggle.addEventListener('change', function() {
        if (this.checked) {
            document.body.classList.add('dark-mode'); localStorage.setItem('theme', 'dark');
        } else {
            document.body.classList.remove('dark-mode'); localStorage.setItem('theme', 'light');
        }
        updateThemeLabel();
    });

    function updateThemeLabel() {
        if (themeToggle.checked) {
            labelGelap.classList.add('active'); labelTerang.classList.remove('active');
        } else {
            labelTerang.classList.add('active'); labelGelap.classList.remove('active');
        }
    }
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>
