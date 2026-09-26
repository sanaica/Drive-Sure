# DriveSure – Simple Vehicle Insurance App

A small XAMPP (PHP + MySQL) project where:

- **Customers** register, add vehicles, choose an insurance plan, pay premiums, and file claims (with photo/invoice upload).
- **Admins** log in and approve or reject claims.

Stack: **HTML, CSS, JavaScript, PHP, MySQL** only. No frameworks.

---

## Folder structure

```
Drive-Sure/
├── frontend/          ← All HTML, CSS, JS (what you open in the browser)
│   ├── index.html
│   ├── login.html
│   ├── register.html
│   ├── dashboard.html
│   ├── addCar.html
│   ├── policy.html
│   ├── payment.html
│   ├── claim.html
│   ├── admin-login.html
│   ├── admin-dashboard.html
│   ├── style.css
│   ├── script.js
│   └── claims.js
│
├── api/               ← PHP backend (JSON API)
│   ├── index.php      ← All routes
│   ├── db.php         ← Database connection
│   └── .htaccess      ← Pretty URLs
│
├── database/
│   └── schema.sql     ← Create tables + default admin
│
├── uploads/           ← Claim photos/invoices (created automatically)
│
└── README.md
```

---

## Setup (XAMPP)

1. **Start XAMPP**  
   Start **Apache** and **MySQL**.

2. **Put the project in htdocs**  
   Copy the whole `Drive-Sure` folder to:
   ```
   C:\xampp\htdocs\Drive-Sure
   ```

3. **Create the database**  
   - Open http://localhost/phpmyadmin  
   - Import `database/schema.sql` and `database/alter_evidence_analysis.sql`
   - Or run the SQL in the SQL tab.

4. **Open the app**  
   - Customer: http://localhost/Drive-Sure/frontend/login.html  
   - Admin:    http://localhost/Drive-Sure/frontend/admin-login.html  

5. **Default admin account** (created by schema.sql)  
   - Email: `admin@drivesure.com`  
   - Password: `admin123`

The frontend talks to the API at:
```
http://localhost/Drive-Sure/api
```
(This is set in `script.js` as `DEFAULT_API_BASE`.)

---

## How the flow works

### Customer
1. Register → Login  
2. **Add vehicle** (make, model, plate, year)  
3. **Choose policy** (link a plan to a vehicle)  
4. Optionally **record a payment**  
5. **File a claim** (pick policy, incident details, upload damage pics + invoices)  
6. See claim status on the dashboard (Pending / Approved / Rejected)

### Admin
1. Login at admin-login.html  
2. See all claims (pending first)  
3. **Approve** or **Reject** (optional short note)  
4. Customer sees the updated status on their dashboard

---

## API endpoints (simple overview)

| Method | Route                    | Who      | Purpose                    |
|--------|--------------------------|----------|----------------------------|
| POST   | /auth/register           | Customer | Register                   |
| POST   | /auth/login              | Customer | Login                      |
| POST   | /admin/login             | Admin    | Admin login                |
| GET    | /vehicles/{customer_id}  | Customer | List vehicles              |
| POST   | /vehicles                | Customer | Add vehicle                |
| GET    | /policies/{customer_id}  | Customer | List policies              |
| POST   | /policies                | Customer | Select plan                |
| GET    | /payments/{customer_id}  | Customer | List payments              |
| POST   | /payments                | Customer | Record payment             |
| GET    | /claims/{customer_id}    | Customer | List own claims            |
| POST   | /claims                  | Customer | File claim (multipart)     |
| GET    | /admin/claims            | Admin    | List all claims            |
| POST   | /admin/claims/update     | Admin    | Approve / Reject           |

---

## Notes

- Keep it simple: no JWT, sessions are stored in browser `localStorage`.
- Uploaded files go into `Drive-Sure/uploads/`.
- Change the admin password after first use in real deployments.
- If the API is not found, check that Apache is running and the path in `script.js` matches your folder name.
