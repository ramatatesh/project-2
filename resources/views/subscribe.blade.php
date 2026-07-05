<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscribe to Khibrat HR</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f5f7fb;color:#1f2937;padding:30px;}
        form{background:white;padding:24px;border-radius:12px;max-width:600px;margin:0 auto;box-shadow:0 10px 30px rgba(0,0,0,0.08);}
        input, select{width:100%;padding:10px;margin:8px 0 16px;border:1px solid #d1d5db;border-radius:8px;}
        button{background:#2563eb;color:white;border:0;padding:12px 18px;border-radius:8px;cursor:pointer;}
    </style>
</head>
<body>
    <form action="/api/companies/register" method="POST">
        <h2>Company Subscription</h2>
        <input name="name" placeholder="Company Name" required>
        <input name="email" type="email" placeholder="Company Email" required>
        <input name="address" placeholder="Company Address" required>
        <input name="contact_name" placeholder="Account Contact Name" required>
        <input name="phone" placeholder="Phone" required>
        <select name="plan_id">
            <option value="free">Free Plan</option>
            <option value="paid">Paid Plan</option>
        </select>
        <input type="hidden" name="payment_status" value="paid">
        <button type="submit">Confirm</button>
    </form>
</body>
</html>
