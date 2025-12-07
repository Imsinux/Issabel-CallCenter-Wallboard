<?php
session_start();
$error = '';

// CREDENTIALS:
// Username: admin
// Password: WallBoard
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    if ($user === 'admin' && $pass === 'WallBoard') {
        $_SESSION['logged_in'] = true;
        // REDIRECT TO WALLBOARD
        header("Location: callcenter.php");
        exit;
    } else {
        $error = 'Access Denied';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome - Login</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Nunito:wght@600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    :root {
        --bg-off: #080808; --bg-on: #1a1a1a;
        --lamp-off: #333; --lamp-on: #fbbf24;       
        --rope-base: #d4b483; --rope-shadow: #a17f4b;
        --accent: #f59e0b; --neon-glow: #ffae00;
        --transition-speed: 0.8s;
    }
    * { box-sizing: border-box; }
    body { margin: 0; height: 100vh; background-color: var(--bg-off); font-family: 'Nunito', sans-serif; display: flex; justify-content: center; align-items: center; overflow: hidden; transition: background-color var(--transition-speed) ease; }
    .scene { position: relative; display: flex; align-items: center; gap: 60px; z-index: 10; }
    .lamp-wrapper { position: relative; width: 260px; height: 500px; z-index: 20; }
    svg.lamp-svg { width: 100%; height: 100%; overflow: visible; }
    .shade { fill: var(--lamp-off); transition: fill var(--transition-speed), filter var(--transition-speed); }
    .stand { fill: #444; } .base { fill: #777; } .face-part { opacity: 0; transition: opacity 0.5s ease; }
    .cord-group { cursor: grab; transform-origin: 100px 0; transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    .cord-group:active { transform: translateY(30px); cursor: grabbing; }
    .rope-base { stroke: var(--rope-base); stroke-width: 6; stroke-linecap: round; fill: none; }
    .rope-twist { stroke: var(--rope-shadow); stroke-width: 6; stroke-dasharray: 2 4; stroke-linecap: butt; fill: none; opacity: 0.6; }
    .knot { fill: var(--rope-base); }
    .click-area { fill: transparent; cursor: pointer; }
    .light-cone { position: absolute; top: 150px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 200px solid transparent; border-right: 200px solid transparent; border-bottom: 800px solid rgba(251, 191, 36, 0); filter: blur(40px); z-index: 1; pointer-events: none; transition: border-bottom-color var(--transition-speed) ease; margin-left: -130px; }
    .content-wrapper { display: flex; flex-direction: column; align-items: center; position: relative; z-index: 15; }
    .brand-title { font-family: 'Montserrat', sans-serif; font-size: 3rem; font-weight: 900; color: transparent; text-transform: uppercase; letter-spacing: 4px; margin-bottom: 25px; opacity: 0; transform: translateY(-20px); transition: all var(--transition-speed) ease; -webkit-text-stroke: 1px rgba(255,255,255,0.2); }
    .login-card { width: 340px; padding: 40px; background: rgba(20, 20, 20, 0.95); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; opacity: 0; transform: translateY(30px); pointer-events: none; transition: all var(--transition-speed) cubic-bezier(0.25, 1, 0.5, 1); }
    .login-card h3 { margin: 0 0 20px; color: #bbb; text-align: center; font-weight: 700;}
    .input-field { width: 100%; padding: 14px; margin-bottom: 15px; background: #252525; border: 1px solid #444; border-radius: 8px; color: white; font-family: inherit; outline: none; transition: 0.3s; }
    .input-field:focus { border-color: var(--accent); background: #333; }
    .btn { width: 100%; padding: 14px; background: var(--accent); color: #000; border: none; border-radius: 8px; font-weight: 800; text-transform: uppercase; cursor: pointer; transition: 0.3s; }
    .btn:hover { background: var(--neon-glow); box-shadow: 0 0 20px rgba(245, 158, 11, 0.5); }
    body.is-on { background-color: var(--bg-on); }
    body.is-on .shade { fill: var(--lamp-on); filter: drop-shadow(0 0 40px rgba(251, 191, 36, 0.6)); }
    body.is-on .face-part { opacity: 0.8; }
    body.is-on .light-cone { border-bottom-color: rgba(251, 191, 36, 0.1); }
    body.is-on .brand-title { opacity: 1; transform: translateY(0); color: #fff; -webkit-text-stroke: 0; text-shadow: 0 0 15px var(--neon-glow); border-bottom: 4px solid var(--accent); padding-bottom: 10px; }
    body.is-on .login-card { opacity: 1; transform: translateY(0); pointer-events: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.5); }

    .github-link {
        position: absolute;
        bottom: 20px;
        right: 20px;
        color: #777; /* Default color in dark mode */
        text-decoration: none;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: color 0.3s ease, transform 0.3s ease;
        z-index: 100; /* Ensure it's on top */
    }
    .github-link:hover {
        color: #fff; /* White on hover */
        transform: scale(1.05);
    }
    body.is-on .github-link {
        color: #ddd; /* Lighter color when the light is on */
    }
    .github-link .fa-github {
        font-size: 1.2rem;
    }
</style>
</head>
<body>
    <div class="light-cone"></div>
    <div class="scene">
        <div class="lamp-wrapper">
            <svg class="lamp-svg" viewBox="0 0 200 500">
                <g class="cord-group" id="pullCord">
                    <line x1="100" y1="140" x2="100" y2="300" class="rope-base" />
                    <line x1="100" y1="140" x2="100" y2="300" class="rope-twist" />
                    <path d="M94 300 C 90 310, 90 320, 100 325 C 110 320, 110 310, 106 300 Z" class="knot" />
                    <line x1="97" y1="325" x2="95" y2="335" stroke="#d4b483" stroke-width="2" />
                    <line x1="100" y1="325" x2="100" y2="338" stroke="#d4b483" stroke-width="2" />
                    <line x1="103" y1="325" x2="105" y2="335" stroke="#d4b483" stroke-width="2" />
                    <rect x="60" y="140" width="80" height="220" class="click-area" />
                </g>
                <rect x="94" y="140" width="12" height="260" rx="4" class="stand" />
                <ellipse cx="100" cy="410" rx="60" ry="15" class="base" />
                <path d="M50 140 L 150 140 L 170 40 L 30 40 Z" class="shade" />
                <g class="face-part">
                    <circle cx="80" cy="90" r="4" fill="#333" />
                    <circle cx="120" cy="90" r="4" fill="#333" />
                    <path d="M90 100 Q100 115 110 100" fill="none" stroke="#333" stroke-width="3" stroke-linecap="round" />
                    <path d="M98 108 Q100 115 102 108" fill="#ff70a6" />
                </g>
            </svg>
        </div>
        <div class="content-wrapper">
            <div class="brand-title">Welcome</div>
            <div class="login-card">
                <h3>Wallboard Access</h3>
                <?php if($error): ?><div style="color:#ff4444;font-size:12px;text-align:center;margin-bottom:10px"><?php echo $error; ?></div><?php endif; ?>
                <form method="post">
                    <input type="text" name="username" class="input-field" placeholder="Username" autocomplete="off">
                    <input type="password" name="password" class="input-field" placeholder="Password">
                    <button type="submit" class="btn">Login</button>
                </form>
            </div>
        </div>
    </div>
    
    <a href="https://github.com/imsinux" class="github-link" target="_blank" rel="noopener noreferrer">
        <i class="fab fa-github"></i> 
        imsinux
    </a>

<script>
    const cord = document.getElementById('pullCord');
    cord.addEventListener('click', () => document.body.classList.toggle('is-on'));
    <?php if($error): ?>document.body.classList.add('is-on');<?php endif; ?>
</script>
</body>
</html>