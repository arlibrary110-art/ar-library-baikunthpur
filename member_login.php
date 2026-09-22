<?php

session_start();

if (isset($_SESSION["member_app_id"])) {

    header(
        "Location: member_app.php"
    );

    exit;
}

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>AR Library Member Login</title>

<style>

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    min-height: 100vh;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #f2f4fb;

    font-family: Arial, sans-serif;
}

.card {

    width: min(420px, 92%);

    background: white;

    padding: 30px;

    border-radius: 22px;

    box-shadow:
        0 15px 45px
        rgba(0,0,0,.12);
}

h1 {
    color: #29335c;

    margin-bottom: 5px;
}

p {
    color: #777;
}

label {

    display: block;

    margin-top: 18px;

    margin-bottom: 7px;

    font-weight: bold;
}

input {

    width: 100%;

    padding: 13px;

    border: 1px solid #ddd;

    border-radius: 10px;

    font-size: 16px;
}

button {

    width: 100%;

    margin-top: 22px;

    padding: 14px;

    border: 0;

    border-radius: 11px;

    background: #29335c;

    color: white;

    font-size: 16px;

    font-weight: bold;
}

#message {

    margin-top: 15px;

    text-align: center;

    color: #c03955;
}

</style>

</head>

<body>

<div class="card">

    <img src="assets/ar-library-logo.webp" alt="AR Library logo" style="width:110px;height:110px;object-fit:contain;display:block;margin:0 auto 8px;border-radius:20px">
    <h1>
        AR LIBRARY
    </h1>

    <p>
        Member App Login
    </p>


    <label>
        Member ID
    </label>

    <input
        id="memberId"
        type="text"
        placeholder="MEM-001"
    >


    <label>
        Registered Phone
    </label>

    <input
        id="phone"
        type="tel"
        placeholder="9876543210"
    >


    <button
        type="button"
        onclick="memberLogin()"
    >
        Login
    </button>


    <div id="message"></div>

</div>


<script>

async function memberLogin()
{

    const memberId =
        document
            .getElementById(
                "memberId"
            )
            .value
            .trim();


    const phone =
        document
            .getElementById(
                "phone"
            )
            .value
            .trim();


    const message =
        document
            .getElementById(
                "message"
            );


    if (!memberId || !phone) {

        message.textContent =
            "Member ID and phone are required";

        return;
    }


    const form =
        new FormData();

    form.append(
        "member_id",
        memberId
    );

    form.append(
        "phone",
        phone
    );


    try {

        const response =
            await fetch(
                "member_api.php?action=login",
                {
                    method: "POST",
                    body: form
                }
            );


        const data =
            await response.json();


        if (data.success) {

            window.location.href =
                "member_app.php";

        } else {

            message.textContent =
                data.message ||
                "Login failed";
        }

    }
    catch(error) {

        console.error(error);

        message.textContent =
            "Server connection error";
    }
}

</script>

</body>

</html>