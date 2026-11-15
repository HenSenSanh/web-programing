function setError(id, message) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = message;
    }
}

function clearErrors() {
    const errors = document.querySelectorAll(".error");
    errors.forEach(function (error) {
        error.textContent = "";
    });
}

function isValidEmail(email) {
    const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return pattern.test(email);
}

function validatePassword(password) {
    if (password.length < 6) {
        return "Mật khẩu phải có ít nhất 6 ký tự.";
    }
    return "";
}

// Validation form Đăng ký (Register)
const registerForm = document.getElementById("registerForm");
if (registerForm) {
    registerForm.addEventListener("submit", function (event) {
        clearErrors();
        let isValid = true;

        const username = document.getElementById("username").value.trim();
        const email = document.getElementById("email").value.trim();
        const password = document.getElementById("password").value;
        const confirmPassword = document.getElementById("confirm_password").value;

        if (username.length < 3) {
            setError("usernameError", "User name phải có ít nhất 3 ký tự.");
            isValid = false;
        }

        if (!isValidEmail(email)) {
            setError("emailError", "Email không hợp lệ.");
            isValid = false;
        }

        const passwordError = validatePassword(password);
        if (passwordError !== "") {
            setError("passwordError", passwordError);
            isValid = false;
        }

        if (confirmPassword !== password) {
            setError("confirmPasswordError", "Mật khẩu xác nhận không khớp.");
            isValid = false;
        }

        if (!isValid) {
            event.preventDefault();
        }
    });
}

// Validation form Đăng nhập (Login)
const loginForm = document.getElementById("loginForm");
if (loginForm) {
    loginForm.addEventListener("submit", function (event) {
        clearErrors();
        let isValid = true;

        const usernameOrEmail = document.getElementById("username_or_email").value.trim();
        const password = document.getElementById("login_password").value;

        if (usernameOrEmail.length === 0) {
            setError("loginUsernameError", "Vui lòng nhập user name hoặc email.");
            isValid = false;
        }

        if (password.length === 0) {
            setError("loginPasswordError", "Vui lòng nhập mật khẩu.");
            isValid = false;
        }

        if (!isValid) {
            event.preventDefault();
        }
    });
}
