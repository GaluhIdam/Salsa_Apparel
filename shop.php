<?php

require_once "config/database.php";

/*
|--------------------------------------------------------------------------
| Ambil semua produk
|--------------------------------------------------------------------------
*/

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
    INNER JOIN categories c
        ON p.category_id = c.id
    ORDER BY p.id DESC
");

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<?php include "includes/header.php"; ?>

<?php include "includes/navbar.php"; ?>


<!-- SHOP HEADER -->
<section class="py-5 bg-light">

    <div class="container text-center">

        <p class="text-muted text-uppercase mb-2">
            Our Collection
        </p>

        <h1 class="fw-bold">
            Shop
        </h1>

        <p class="text-muted mb-0">
            Temukan produk fashion pilihan dari Salsa Apparel.
        </p>

    </div>

</section>


<!-- PRODUCT LIST -->
<section class="py-5">
    <div class="container">
        <div class="row g-4">
            <?php if (empty($products)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <h4>
                            Belum ada produk
                        </h4>

                        <p class="text-muted">
                            Produk belum tersedia saat ini.
                        </p>
                    </div>
                </div>

            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card product-card h-100">

                            <!-- PRODUCT IMAGE -->
                            <div
                                class="product-image
                                       d-flex
                                       align-items-center
                                       justify-content-center"
                            >

                                <?php if (!empty($product["image"])): ?>

                                    <img
                                        src="assets/images/<?php echo htmlspecialchars($product["image"]); ?>"
                                        alt="<?php echo htmlspecialchars($product["name"]); ?>"
                                        class="img-fluid"
                                    >

                                <?php else: ?>

                                    <span class="text-muted">
                                        Product Image
                                    </span>

                                <?php endif; ?>

                            </div>


                            <!-- PRODUCT INFO -->
                            <div class="card-body">

                                <p class="text-muted small mb-1">
                                    <?php echo htmlspecialchars($product["category_name"]); ?>
                                </p>

                                <h5 class="card-title">
                                    <?php echo htmlspecialchars($product["name"]); ?>
                                </h5>

                                <p class="card-text text-muted">
                                    <?php echo htmlspecialchars($product["description"]); ?>
                                </p>

                                <div class="d-flex justify-content-between align-items-center">

                                    <strong>
                                        Rp<?php echo number_format($product["price"], 0, ",", "."); ?>
                                    </strong>

                                    <?php if ($product["stock"] > 0): ?>

                                        <span class="badge text-bg-success">
                                            Stock <?php echo $product["stock"]; ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="badge text-bg-danger">
                                            Sold Out
                                        </span>

                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- PRODUCT ACTION -->
                            <div class="card-footer bg-white border-0 p-3">
                                <a
                                    href="product-detail.php?id=<?php echo $product["id"]; ?>"
                                    class="btn btn-dark w-100"
                                >
                                    View Product
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>


<?php include "includes/footer.php"; ?>