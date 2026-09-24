<?php

session_start();

require_once "config/database.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {

        $message = "Email dan password wajib diisi.";
        $message_type = "danger";

    } else {

        $stmt = $pdo->prepare("
            SELECT id, name, email, password, role
            FROM users
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute([
            ":email" => $email
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user["password"])) {

            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["user_name"] = $user["name"];
            $_SESSION["user_email"] = $user["email"];
            $_SESSION["user_role"] = $user["role"];

            header("Location: index.php");
            exit;

        } else {

            $message = "Email atau password salah.";
            $message_type = "danger";

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
                                Welcome Back
                            </h2>

                            <p class="text-muted">
                                Login ke akun Salsa Apparel
                            </p>

                        </div>


                        <?php if ($message !== ""): ?>

                            <div class="alert alert-<?php echo $message_type; ?>">

                                <?php echo htmlspecialchars($message); ?>

                            </div>

                        <?php endif; ?>


                        <form method="POST">


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


                            <!-- PASSWORD -->
                            <div class="mb-4">

                                <label class="form-label">
                                    Password
                                </label>

                                <input
                                    type="password"
                                    name="password"
                                    class="form-control"
                                    placeholder="Masukkan password"
                                    required
                                >

                            </div>


                            <button
                                type="submit"
                                class="btn btn-dark w-100"
                            >
                                Login
                            </button>


                        </form>


                        <div class="text-center mt-4">

                            <span class="text-muted">
                                Belum punya akun?
                            </span>

                            <a href="register.php">
                                Register
                            </a>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<?php include "includes/footer.php"; ?>