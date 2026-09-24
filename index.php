<?php

session_start();

require_once "config/database.php";

$stmt = $pdo->query("
    SELECT
        p.id,
        p.name,
        p.description,
        p.price,
        p.stock,
        p.image,
        c.name AS category_name
    FROM products p
    JOIN categories c
        ON p.category_id = c.id
    ORDER BY p.id DESC
    LIMIT 6
");

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<?php include "includes/header.php"; ?>
<?php include "includes/navbar.php"; ?>

<?php if (isset($_SESSION["user_id"])): ?>
    <div class="container mt-3">
        <div class="alert alert-success">
            Selamat datang,
            <strong>
                <?php echo htmlspecialchars($_SESSION["user_name"]); ?>
            </strong>
        </div>
    </div>
<?php endif; ?>

<!-- HERO -->
<section class="hero">

    <div class="container">

        <div class="row align-items-center">

            <div class="col-lg-6">

                <p class="text-uppercase text-muted">
                    Welcome to Salsa Apparel
                </p>

                <h1 class="hero-title">
                    Fashion for Your Everyday.
                </h1>

                <p class="hero-text mt-4">
                    Temukan koleksi fashion pilihan Salsa Apparel
                    untuk melengkapi gaya sehari-hari kamu.
                </p>

                <div class="mt-4">

                    <a
                        href="shop.php"
                        class="btn btn-dark btn-lg px-4"
                    >
                        Shop Now
                    </a>

                </div>

            </div>

            <div class="col-lg-6 mt-5 mt-lg-0">

                <div class="bg-dark text-white p-5 text-center">

                    <h2>
                        SALSA
                    </h2>

                    <p class="mb-0">
                        APPAREL
                    </p>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- FEATURED PRODUCTS -->
<section class="py-5">

    <div class="container">

        <div class="text-center">

            <p class="text-muted mb-1">
                OUR COLLECTION
            </p>

            <h2 class="section-title">
                Featured Products
            </h2>

        </div>


        <div class="row g-4">

            <!-- PRODUCT 1 -->
            <div class="col-md-4">

                <div class="card product-card">

                    <div class="product-image
                                d-flex
                                align-items-center
                                justify-content-center">

                        <span class="text-muted">
                            Product Image
                        </span>

                    </div>

                    <div class="card-body">

                        <h5 class="card-title">
                            Basic T-Shirt
                        </h5>

                        <p class="text-muted">
                            T-Shirt
                        </p>

                        <strong>
                            Rp120.000
                        </strong>

                    </div>

                </div>

            </div>


            <!-- PRODUCT 2 -->
            <div class="col-md-4">

                <div class="card product-card">

                    <div class="product-image
                                d-flex
                                align-items-center
                                justify-content-center">

                        <span class="text-muted">
                            Product Image
                        </span>

                    </div>

                    <div class="card-body">

                        <h5 class="card-title">
                            Essential Hoodie
                        </h5>

                        <p class="text-muted">
                            Hoodie
                        </p>

                        <strong>
                            Rp250.000
                        </strong>

                    </div>

                </div>

            </div>


            <!-- PRODUCT 3 -->
            <div class="col-md-4">

                <div class="card product-card">

                    <div class="product-image
                                d-flex
                                align-items-center
                                justify-content-center">

                        <span class="text-muted">
                            Product Image
                        </span>

                    </div>

                    <div class="card-body">

                        <h5 class="card-title">
                            Casual Shirt
                        </h5>

                        <p class="text-muted">
                            Shirt
                        </p>

                        <strong>
                            Rp180.000
                        </strong>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<?php include "includes/footer.php"; ?>