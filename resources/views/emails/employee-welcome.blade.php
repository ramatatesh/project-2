<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Welcome to Khibrat</title>
</head>
<body>
    <h2>Welcome to Khibrat</h2>
    <p>Hello {{ $fullName }},</p>
    <p>Your employee account has been created successfully.</p>
    <ul>
        <li><strong>Email:</strong> {{ $email }}</li>
        <li><strong>Temporary Password:</strong> {{ $temporaryPassword }}</li>
    </ul>
    <p>You can log in using the link below and change your password on first login:</p>
    <p><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>
    <p>Thank you for using Khibrat.</p>
</body>
</html>
