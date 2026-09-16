# Drive-Sure
Drive Sure is an application for users to log accidents, upload damage photos, and submit repair bills. It manages current claims while setting the stage for future AI RAG analysis and visual fraud detection.

## Setup Instructions (Local XAMPP Environment)

1. **Start XAMPP Control Panel**: Ensure that both **Apache** and **MySQL** modules are running.
2. **Database Setup**:
   - The database schema is already created in the `drivesure_db` database via the `schema.sql` file.
   - If you need to recreate it, you can import `schema.sql` into phpMyAdmin (accessible at `http://localhost/phpmyadmin/`).
3. **Application Location**:
   - Make sure this `Drive-Sure` folder is placed inside `C:\xampp\htdocs\`.
4. **Access the App**:
   - Open your browser and navigate to `http://localhost/Drive-Sure/index.html`.
5. **API Configuration**:
   - The application is now configured to use the local PHP API endpoints located in the `api/` folder.
   - `script.js` has been updated to use `http://localhost/Drive-Sure/api` as the base URL.
