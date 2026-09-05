<?php

session_start();

$error = '';

// CREDENTIALS:
// Username: admin
// Password:

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    if ($user === 'admin' && $pass === 'WallBorAd@2025!#$@') {
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
<title>IRAN SOLAR - Login</title>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        min-height: 100vh;
        font-family: 'Poppins', sans-serif;
        display: flex;
        justify-content: center;
        align-items: center;
        overflow: hidden;
        background: #2d1b69;
    }

    /* -- IMAGE BACKGROUND -- */
    .bg {
        position: fixed;
        inset: 0;
        z-index: 0;
        background-image: url('login-bg.png');
        background-size: cover;
        background-position: center center;
        background-repeat: no-repeat;
    }

    /* Subtle dark overlay to ensure readability */
    .bg::after {
        content: '';
        position: absolute;
        inset: 0;
        background: rgba(20, 10, 60, 0.35);
    }

    /* Stars */
    .stars {
        position: fixed;
        inset: 0;
        z-index: 1;
    }
    .star {
        position: absolute;
        background: #fff;
        border-radius: 50%;
        animation: twinkle var(--d) ease-in-out infinite alternate;
    }
    @keyframes twinkle {
        from { opacity: 0.1; transform: scale(0.8); }
        to   { opacity: 0.9; transform: scale(1.2); }
    }

    /* -- CARD -- */
    .card-wrap {
        position: relative;
        z-index: 10;
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
        padding: 20px;
    }

    .login-card {
        width: 340px;
        background: rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 20px;
        padding: 40px 32px 32px;
        box-shadow:
            0 8px 60px rgba(0,0,0,0.35),
            inset 0 1px 0 rgba(255,255,255,0.15);
    }

    .login-card h2 {
        text-align: center;
        color: #fff;
        font-size: 1.7rem;
        font-weight: 600;
        margin-bottom: 28px;
        letter-spacing: 0.5px;
        text-shadow: 0 2px 12px rgba(0,0,0,0.3);
    }

    /* -- INPUT GROUP -- */
    .input-group {
        position: relative;
        margin-bottom: 18px;
    }

    .input-group input {
        width: 100%;
        padding: 13px 44px 13px 16px;
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 10px;
        color: #fff;
        font-size: 0.9rem;
        font-family: 'Poppins', sans-serif;
        outline: none;
        transition: border-color 0.3s, background 0.3s;
    }

    .input-group input::placeholder {
        color: rgba(255,255,255,0.55);
        font-size: 0.85rem;
    }

    .input-group input:focus {
        background: rgba(255,255,255,0.18);
        border-color: rgba(255,255,255,0.45);
    }

    .input-icon {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: rgba(255,255,255,0.55);
        pointer-events: none;
    }

    /* -- REMEMBER / FORGOT -- */
    .row-options {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 22px;
        font-size: 0.78rem;
        color: rgba(255,255,255,0.7);
    }

    .row-options label {
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
    }

    .row-options input[type="checkbox"] {
        accent-color: #a78bfa;
        width: 14px;
        height: 14px;
        cursor: pointer;
    }

    .row-options a {
        color: rgba(255,255,255,0.7);
        text-decoration: none;
        transition: color 0.2s;
    }

    .row-options a:hover {
        color: #fff;
    }

    /* -- BUTTON -- */
    .btn-login {
        width: 100%;
        padding: 13px;
        background: #fff;
        color: #3b1f8c;
        border: none;
        border-radius: 50px;
        font-size: 1rem;
        font-weight: 600;
        font-family: 'Poppins', sans-serif;
        cursor: pointer;
        letter-spacing: 0.5px;
        transition: background 0.3s, transform 0.2s, box-shadow 0.3s;
        box-shadow: 0 4px 20px rgba(0,0,0,0.25);
        margin-bottom: 20px;
    }

    .btn-login:hover {
        background: #f0e8ff;
        transform: translateY(-2px);
        box-shadow: 0 8px 28px rgba(0,0,0,0.3);
    }

    .btn-login:active {
        transform: translateY(0);
    }

    /* -- REGISTER LINK -- */
    .register-link {
        text-align: center;
        font-size: 0.8rem;
        color: rgba(255,255,255,0.6);
    }

    .register-link a {
        color: rgba(255,255,255,0.85);
        font-weight: 500;
        text-decoration: none;
        transition: color 0.2s;
    }

    .register-link a:hover {
        color: #fff;
    }

    /* -- ERROR -- */
    .error-msg {
        background: rgba(248, 113, 113, 0.15);
        border: 1px solid rgba(248, 113, 113, 0.35);
        color: #fca5a5;
        border-radius: 8px;
        padding: 9px 12px;
        font-size: 0.8rem;
        text-align: center;
        margin-bottom: 16px;
    }
</style>
</head>
<body>

<!-- Image Background -->
<div class="bg"></div>

<!-- Stars -->
<div class="stars" id="stars"></div>

<!-- Login Card -->
<div class="card-wrap">
    <div class="login-card">
        <h2>Login</h2>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="input-group">
                <input type="text" name="username" placeholder="Username" autocomplete="off"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                <span class="input-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </span>
            </div>

            <div class="input-group">
                <input type="password" name="password" placeholder="Password">
                <span class="input-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </span>
            </div>

            <div class="row-options">
                <label>
                    <input type="checkbox" name="remember"> Remember me
                </label>
                <a href="#">Forgot password?</a>
            </div>

            <button type="submit" class="btn-login">Login</button>
        </form>

        <div class="register-link">
            Don't have an account? <a href="#">Register</a>
        </div>
    </div>
</div>

<script>
    // Generate stars
    (function() {
        const container = document.getElementById('stars');
        const count = 120;
        for (let i = 0; i < count; i++) {
            const s = document.createElement('div');
            s.className = 'star';
            const size = Math.random() * 2.5 + 0.5;
            s.style.cssText = `
                width:${size}px; height:${size}px;
                left:${Math.random()*100}%;
                top:${Math.random()*70}%;
                --d:${(Math.random()*3+1.5).toFixed(1)}s;
                animation-delay:${(Math.random()*4).toFixed(1)}s;
            `;
            container.appendChild(s);
        }
    })();
</script>
</body>
</html>