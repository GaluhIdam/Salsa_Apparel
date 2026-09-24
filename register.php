<?php

require_once "config/database.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    // Validasi nama
    if ($name === "") {

        $message = "Nama wajib diisi.";
        $message_type = "danger";

    // Validasi email
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Format email tidak valid.";
        $message_type = "danger";

    // Validasi password
    } elseif (strlen($password) < 6) {

        $message = "Password minimal 6 karakter.";
        $message_type = "danger";

    // Konfirmasi password
    } elseif ($password !== $confirm_password) {

        $message = "Konfirmasi password tidak cocok.";
        $message_type = "danger";

    } else {

        // Cek apakah email sudah digunakan
        $check = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email
        ");

        $check->execute([
            ":email" => $email
        ]);

        if ($check->fetch()) {

            $message = "Email sudah terdaftar.";
            $message_type = "danger";

        } else {

            // Hash password
            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            // Simpan user
            $stmt = $pdo->prepare("
                INSERT INTO users
                (name, email, password, role)
                VALUES
                (:name, :email, :password, 'customer')
            ");

            $stmt->execute([
                ":name" => $name,
                ":email" => $email,
                ":password" => $hashed_password
            ]);

            $message = "Registrasi berhasil. Silakan login.";
            $message_type = "success";

        }
    }
}

?>

<?php include "includes/header.php"; ?>
<?php include "includes/navbar.php"; ?>


<section class="py-5">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <h2 class="fw-bold">
                                Create Account
                            </h2>

                            <p class="text-muted">
                                Buat akun Salsa Apparel
                            </p>
                        </div>

                        <?php if ($message !== ""): ?>

                            <div class="alert alert-<?php echo $message_type; ?>">
                                <?php echo htmlspecialchars($message); ?>
                            </div>

                        <?php endif; ?>

                        <form method="POST">

                            <!-- NAME -->
                            <div class="mb-3">

                                <label class="form-label">
                                    Nama
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    class="form-control"
                                    placeholder="Masukkan nama"
                                    required
                                    value="<?php echo htmlspecialchars($_POST["name"] ?? ""); ?>"
                                >
                            </div>

                            <!-- EMAIL -->
                            <div class="mb-3">
                                <label class="form-label">
                                    Email
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    placeholder="Masukkan email"
                                    required
                                    value="<?php echo htmlspecialchars($_POST["email"] ?? ""); ?>"
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Password
                                </label>

                                <input
                                    type="password"
                                    name="password"
                                    class="form-control"
                                    placeholder="Minimal 6 karakter"
                                    required
                                >
                            </div>

                            <div class="mb-4">
                                <label class="form-label">
                                    Konfirmasi Password
                                </label>

                                <input
                                    type="password"
                                    name="confirm_password"
                                    class="form-control"
                                    placeholder="Ulangi password"
                                    required
                                >
                            </div>

                            <button
                                type="submit"
                                class="btn btn-dark w-100"
                            >
                                Register
                            </button>
                        </form>

                        <div class="text-center mt-4">
                            <span class="text-muted">
                                Sudah punya akun?
                            </span>

                            <a href="login.php">
                                Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include "includes/footer.php"; ?>