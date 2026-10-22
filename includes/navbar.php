<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

?>

<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            SALSA APPAREL
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarSalsa"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div
            class="collapse navbar-collapse"
            id="navbarSalsa"
        >
            <ul class="navbar-nav mx-auto">

                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        Home
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="shop.php">
                        Shop
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#">
                        Categories
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#">
                        About
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php if (isset($_SESSION["user_id"])): ?>
                    <span class="text-muted small">
                        Hi,
                        <strong>
                            <?php echo htmlspecialchars($_SESSION["user_name"]); ?>
                        </strong>
                    </span>

                    <a
                        href="#"
                        class="btn btn-dark btn-sm"
                    >
                        Cart
                    </a>

                    <a
                        href="logout.php"
                        class="btn btn-outline-dark btn-sm"
                    >
                        Logout
                    </a>

                <?php else: ?>

                    <a
                        href="login.php"
                        class="btn btn-outline-dark btn-sm"
                    >
                        Login
                    </a>

                    <a
                        href="register.php"
                        class="btn btn-dark btn-sm"
                    >
                        Register
                    </a>

                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>