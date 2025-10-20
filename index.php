<?php
// Set the content type to HTML to render the provided template
header('Content-Type: text/html; charset=utf-8');

function getMetaFast(?string $html, string $prop): ?string
{
    if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '/') . '["\'][^>]*content=["\']([^"\']+)["\']/i', $html ?? '', $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return null;
}

// Initialize variables
$phoneNumber = $_GET['number'] ?? null;
$name = null;
$username = null;
$userid = null;
$profileImage = null;
$fbProfileUrl = null;
$callerIdName = null;
$errorMessage = null;

// Validate and process form input if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone_number'])) {
    $phoneNumber = trim($_POST['phone_number']);
    // Remove any + sign if present
    $phoneNumber = ltrim($phoneNumber, '+');
    if (!preg_match('/^\d{10,15}$/', $phoneNumber)) {
        $errorMessage = 'Please enter a valid phone number (10-15 digits, e.g., 8801768174602).';
    } else {
        // Proceed with API calls
    }
} elseif (empty($phoneNumber) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Initial load, show form
    $phoneNumber = '';
}

// --- Step 1: Fetch Caller ID Name ---
if ($phoneNumber && preg_match('/^\d{10,15}$/', $phoneNumber)) {
    $callerIdUrl = 'https://api.eyecon-app.com/app/getnames.jsp?cli=' . urlencode($phoneNumber) . '&lang=en&is_callerid=true&is_ic=true&cv=vc_571_vn_4.2025.06.15.2028_a&requestApi=okHttp&source=OnBoardingView';
    $callerIdHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36',
        'Connection: Keep-Alive',
        'Accept: application/json',
        'Accept-Encoding: gzip',
        'e-auth-v: e1',
        'e-auth: d95135d0-249e-4cbb-82c3-d5eecadc256f',
        'e-auth-c: 40',
        'e-auth-k: PgdtSBeR0MumR7fO',
        'accept-charset: UTF-8',
        'content-type: application/x-www-form-urlencoded; charset=utf-8'
    ];

    $chCallerId = curl_init($callerIdUrl);
    curl_setopt_array($chCallerId, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $callerIdHeaders,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $callerIdResponse = curl_exec($chCallerId);

    if (curl_errno($chCallerId)) {
        error_log("Caller ID API error: " . curl_error($chCallerId));
    } else {
        $callerIdData = json_decode($callerIdResponse, true);
        error_log("Caller ID Response: " . $callerIdResponse); // Debug log
        if (is_array($callerIdData) && !empty($callerIdData) && isset($callerIdData[0]['name'])) {
            $callerIdName = $callerIdData[0]['name'];
        }
    }
    curl_close($chCallerId);

    // --- Step 2: Resolve Phone Number to Facebook Profile URL (or direct image) ---
    $eyeconApiUrl = 'https://api.eyecon-app.com/app/pic?cli=' . urlencode($phoneNumber) . '&is_callerid=true&size=big&type=0&src=OnBoardingView&cancelfresh=0&cv=vc_571_vn_4.2025.06.15.2028_a';
    $eyeconHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36',
        'Host: api.eyecon-app.com',
        'Connection: Keep-Alive',
        'Accept-Encoding: gzip',
        'e-auth-v: e1',
        'e-auth: d95135d0-249e-4cbb-82c3-d5eecadc256f',
        'e-auth-c: 40',
        'e-auth-k: PgdtSBeR0MumR7fO'
    ];

    $chEyecon = curl_init($eyeconApiUrl);
    curl_setopt_array($chEyecon, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $eyeconHeaders,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $eyeconResponse = curl_exec($chEyecon);

    if (curl_errno($chEyecon)) {
        $errorMessage = 'Failed to connect to Eyecon API: ' . htmlspecialchars(curl_error($chEyecon));
    } else {
        $eyeconInfo = curl_getinfo($chEyecon);
        $eyeconHeaderSize = $eyeconInfo['header_size'];
        $eyeconHeader = substr($eyeconResponse, 0, $eyeconHeaderSize);
        $eyeconBody = substr($eyeconResponse, $eyeconHeaderSize);

        // Check if the response is a direct image
        if ($eyeconInfo['content_type'] && stripos($eyeconInfo['content_type'], 'image/') === 0) {
            $profileImage = 'data:image/jpeg;base64,' . base64_encode($eyeconBody);
            $fbProfileUrl = null;
        } else {
            preg_match('/^Location:\s*(.+?)\r?$/mi', $eyeconHeader ?? '', $locationMatch);
            $redirectUrl = $locationMatch[1] ?? null;

            if ($redirectUrl && str_contains($redirectUrl, 'cdn.eyecon-app.com/profile/image/')) {
                $profileImage = $redirectUrl;
                $fbProfileUrl = null;
            } elseif ($redirectUrl && preg_match('/graph\.facebook\.com\/(\d+)\/picture/', $redirectUrl, $userMatch)) {
                $userId = $userMatch[1];
                $fbProfileUrl = "https://www.facebook.com/profile.php?id=$userId";

                $ch1 = curl_init();
                curl_setopt_array($ch1, [
                    CURLOPT_URL => $fbProfileUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PHP scraper)',
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_ENCODING => '',
                    CURLOPT_RANGE => '0-10000',
                ]);

                $encodedUrl = urlencode($fbProfileUrl);
                $ch2 = curl_init();
                curl_setopt_array($ch2, [
                    CURLOPT_URL => "https://facebook-profile-picture-viewer.p.rapidapi.com/?fburl={$encodedUrl}",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_CUSTOMREQUEST => "GET",
                    CURLOPT_HTTPHEADER => [
                        "User-Agent: Dart/3.5 (dart:io)",
                        "Accept-Encoding: gzip",
                        "x-rapidapi-host: facebook-profile-picture-viewer.p.rapidapi.com",
                        "x-rapidapi-key: y76eBTWokKmshCLPwQKtW1hkvASip13jtwGjsnHhlrq4pdoJQy"
                    ],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);

                $mh = curl_multi_init();
                curl_multi_add_handle($mh, $ch1);
                curl_multi_add_handle($mh, $ch2);

                $running = null;
                do {
                    curl_multi_exec($mh, $running);
                    curl_multi_select($mh, 0.05);
                } while ($running > 0);

                $res1 = curl_multi_getcontent($ch1);
                $res2 = curl_multi_getcontent($ch2);

                curl_multi_remove_handle($mh, $ch1);
                curl_multi_remove_handle($mh, $ch2);
                curl_close($ch1);
                curl_close($ch2);
                curl_multi_close($mh);

                if ($res1) {
                    $name = getMetaFast($res1, 'og:title');
                    $ogUrl = getMetaFast($res1, 'og:url');
                    $androidUrl = getMetaFast($res1, 'al:android:url');
                    $ogImage = getMetaFast($res1, 'og:image');
                    $twitterImg = getMetaFast($res1, 'twitter:image');

                    if ($ogUrl && preg_match('~facebook\.com/([^/?&]+)~i', $ogUrl, $match)) {
                        $username = $match[1];
                    }
                    if ($androidUrl && preg_match('~fb://profile/(\d+)~i', $androidUrl, $m)) {
                        $userid = $m[1];
                    }

                    $profileImage = $ogImage ?: $twitterImg;
                    if ($profileImage && preg_match('/cstp=mx?(\d+x\d+)/i', $profileImage, $dimMatch)) {
                        $maxDim = $dimMatch[1];
                        $profileImage = preg_replace('/ctp=[sp]\d+x\d+/i', 'ctp=p' . $maxDim, $profileImage);
                    }
                }

                if ((!$profileImage || !$name) && $res2) {
                    $data = json_decode($res2, true);
                    if (is_array($data)) {
                        if (isset($data['profile_picture'])) {
                            $profileImage = $data['profile_picture'];
                        } elseif (is_string(end($data))) {
                            $profileImage = end($data);
                        }
                    }
                }
            }
        }
    }
    curl_close($chEyecon);
}

// Check if we still have no profile data after all attempts
if (!$name && !$username && !$userid && !$profileImage && !$fbProfileUrl && !$callerIdName && !$errorMessage && $phoneNumber) {
    $errorMessage = 'No profile or caller ID information found for the given number.';
}

// Ensure variables are properly escaped for HTML output
$name_safe = htmlspecialchars($name ?? 'N/A');
$username_safe = htmlspecialchars($username ?? 'N/A');
$userid_safe = htmlspecialchars($userid ?? 'N/A');
$profileImage_safe = htmlspecialchars($profileImage ?? '');
$fbProfileUrl_safe = htmlspecialchars($fbProfileUrl ?? '');
$phoneNumber_safe = htmlspecialchars($phoneNumber ?? '');
$callerIdName_safe = htmlspecialchars($callerIdName ?? 'N/A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Eyecon Caller Info</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            min-height: 100vh;
            background-color: #000;
            overflow-x: hidden;
            position: relative;
            -webkit-text-size-adjust: 100%;
            padding: 20px;
            color: #fff;
        }

        .circle-1, .circle-2 {
            position: fixed;
            border-radius: 50%;
            filter: blur(60px);
            z-index: -1;
        }

        .circle-1 {
            width: 200px;
            height: 200px;
            top: 15%;
            left: 10%;
            background: radial-gradient(circle, rgba(148, 0, 211, 0.6) 0%, rgba(148, 0, 211, 0) 70%);
            animation: neon-glow-move-1 12s ease-in-out infinite alternate;
        }

        .circle-2 {
            width: 250px;
            height: 250px;
            bottom: 10%;
            right: 10%;
            background: radial-gradient(circle, rgba(0, 204, 255, 0.6) 0%, rgba(0, 204, 255, 0) 70%);
            animation: neon-glow-move-2 14s ease-in-out infinite alternate;
        }

        @keyframes neon-glow-move-1 {
            0% { transform: translate(0, 0) scale(1); opacity: 0.8; }
            50% { transform: translate(40px, 30px) scale(1.1); opacity: 1; }
            100% { transform: translate(0, 0) scale(1); opacity: 0.8; }
        }

        @keyframes neon-glow-move-2 {
            0% { transform: translate(0, 0) scale(1); opacity: 0.8; }
            50% { transform: translate(-35px, -25px) scale(1.1); opacity: 1; }
            100% { transform: translate(0, 0) scale(1); opacity: 0.8; }
        }

        .search-container {
            padding: 25px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            box-shadow: 0 8px 24px 0 rgba(0, 0, 0, 0.3);
            color: #fff;
            text-align: center;
            width: 100%;
            max-width: 500px;
            margin-bottom: 20px;
        }

        .search-container h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .search-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .search-input {
            padding: 14px 18px;
            font-size: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            outline: none;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .search-input:focus {
            border-color: rgba(0, 204, 255, 0.7);
            box-shadow: 0 0 0 2px rgba(0, 204, 255, 0.2);
        }

        .search-input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .search-button {
            padding: 14px 25px;
            font-size: 1rem;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(0, 204, 255, 0.8), rgba(148, 0, 211, 0.8));
            color: #fff;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
        }

        .search-button:hover {
            background: linear-gradient(135deg, rgba(0, 204, 255, 0.9), rgba(148, 0, 211, 0.9));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .error-message {
            color: #ff6b6b;
            margin-top: 10px;
            font-size: 0.9rem;
            text-align: center;
            padding: 10px;
            border-radius: 8px;
            background: rgba(255, 107, 107, 0.1);
        }

        .info-text {
            font-size: 0.85rem;
            opacity: 0.7;
            margin-top: 15px;
            text-align: center;
        }

        .glass-container {
            padding: 25px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            box-shadow: 0 8px 24px 0 rgba(0, 0, 0, 0.3);
            color: #fff;
            text-align: center;
            width: 100%;
            max-width: 500px;
            margin-top: 20px;
        }

        .glass-container h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 12px;
            line-height: 1.2;
        }

        .profile-image {
            width: 100%;
            max-width: 100%;
            height: auto;
            max-height: 300px;
            border-radius: 10px;
            margin-bottom: 18px;
            object-fit: cover;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .profile-info {
            text-align: left;
            margin-bottom: 20px;
        }

        .info-row {
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .info-label {
            font-size: 0.75rem;
            opacity: 0.7;
            display: block;
            margin-bottom: 3px;
        }

        .info-value {
            font-size: 0.85rem;
            word-break: break-word;
            line-height: 1.3;
        }

        .cta-button {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            padding: 12px 25px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 0.95rem;
            cursor: pointer;
            transition: background 0.3s ease, transform 0.3s ease;
            display: inline-block;
            width: auto;
            min-width: 140px;
            box-sizing: border-box;
        }

        .cta-button[href] {
            display: inline-block;
        }

        @media (min-width: 768px) {
            .search-form {
                flex-direction: row;
            }

            .search-input {
                flex: 1;
            }

            .search-button {
                min-width: 120px;
            }

            .glass-container {
                padding: 35px;
                border-radius: 20px;
                max-width: 450px;
            }

            .glass-container h1 {
                font-size: 2.2rem;
            }

            .profile-image {
                max-height: 240px;
            }

            .info-label {
                font-size: 0.8rem;
            }

            .info-value {
                font-size: 0.95rem;
            }

            .cta-button {
                padding: 12px 30px;
                font-size: 1rem;
            }

            .circle-1, .circle-2 {
                filter: blur(70px);
            }

            .circle-1 {
                width: 250px;
                height: 250px;
            }

            .circle-2 {
                width: 300px;
                height: 300px;
            }
        }

        .image-only {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: #000;
        }

        .caller-id-info {
            text-align: center;
            margin-top: 15px;
        }

        .loading {
            display: none;
            text-align: center;
            margin-top: 15px;
        }

        .loading-spinner {
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 3px solid rgba(0, 204, 255, 0.8);
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <div class="circle-1"></div>
    <div class="circle-2"></div>

    <div class="search-container">
        <h1>Eyecon Caller Info</h1>
        <form method="POST" action="" class="search-form" id="searchForm">
            <input type="text" name="phone_number" class="search-input" value="<?= htmlspecialchars($phoneNumber ?? '') ?>" placeholder="Enter phone number (e.g., 8801768174602)" required>
            <button type="submit" class="search-button">Search</button>
        </form>
        <div class="loading" id="loadingIndicator">
            <div class="loading-spinner"></div>
            <p>Searching for caller information...</p>
        </div>
        <?php if (isset($errorMessage) && $errorMessage): ?>
            <div class="error-message"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>
        <div class="info-text">Enter a phone number to find caller information</div>
    </div>

    <?php if (isset($phoneNumber) && $phoneNumber && preg_match('/^\d{10,15}$/', $phoneNumber) && !isset($errorMessage)): ?>
        <?php if ($fbProfileUrl === null && $profileImage): ?>
            <div class="glass-container image-only">
                <img src="<?= $profileImage_safe ?>" alt="Profile Image" class="profile-image">
                <?php if ($callerIdName): ?>
                    <div class="caller-id-info">
                        <span class="info-label">Caller ID Name</span>
                        <span class="info-value"><?= $callerIdName_safe ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="glass-container">
                <?php if ($profileImage): ?>
                    <img src="<?= $profileImage_safe ?>" alt="Profile Image" class="profile-image">
                <?php endif; ?>
                
                <h1>Profile Information</h1>        
                <div class="profile-info">
                    <div class="info-row">
                        <span class="info-label">Name</span>
                        <span class="info-value"><?= $name_safe ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Username</span>
                        <span class="info-value"><?= $username_safe ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">User ID</span>
                        <span class="info-value"><?= $userid_safe ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Facebook Profile Link</span>
                        <span class="info-value">
                            <?php if ($fbProfileUrl): ?>
                                <a href="<?= $fbProfileUrl_safe ?>" target="_blank" style="color:inherit; text-decoration: none;"><?= $fbProfileUrl_safe ?></a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone Number</span>
                        <span class="info-value"><?= $phoneNumber_safe ?></span>
                    </div>
                    <?php if ($callerIdName): ?>
                        <div class="info-row">
                            <span class="info-label">Caller ID Name</span>
                            <span class="info-value"><?= $callerIdName_safe ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($fbProfileUrl): ?>
                    <a href="<?= $fbProfileUrl_safe ?>" class="cta-button" target="_blank">View Profile</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <script>
        document.getElementById('searchForm').addEventListener('submit', function() {
            document.getElementById('loadingIndicator').style.display = 'block';
        });
    </script>
</body>
</html>
