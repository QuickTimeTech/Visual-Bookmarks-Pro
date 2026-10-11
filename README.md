# Visual Bookmarks Pro

Visual Bookmarks Pro is a secure, self-hosted PHP/SQLite web application that transforms your saved web links into a visual, desktop-like workspace. It automatically captures webpage screenshots and titles, creating a beautiful and organized personal knowledge base.

## ✨ Features

* **Automated Visuals:** Automatically scrapes webpage titles, retrieves site favicons, and generates visual screenshot previews using the Thum.io API.
* **Desktop-like Experience:** Drag and drop bookmark cards directly into folders, and double-click cards to instantly visit the links.
* **Advanced Organization:** Supports an infinite visual folder hierarchy and a comma-separated multi-tag system for granular filtering.
* **Bulk Management:** Select multiple bookmarks to delete, move to new folders, or apply new tags all at once using the floating action bar.
* **Master Password Security:** The entire application and its API are locked behind a session-based master password to keep your data private.
* **Admin Batch Scanner:** A built-in dashboard tests the uptime of all saved links, force-refreshes missing screenshots, and allows you to bulk-delete dead websites with one click.
* **Browser Integration:** Includes a simple drag-and-drop bookmarklet for your browser's bookmarks bar, allowing you to save any site in one click without leaving the page.
* **HTML Import:** Easily migrate from Chrome, Safari, Firefox, or Edge by importing your standard HTML bookmarks file.
* **Modern UI:** Responsive grid and compact list views, built-in dark mode toggle, and smooth loading states using Bootstrap 5.

## 🛠️ Prerequisites

To run Visual Bookmarks Pro, your web server must support the following:
* **PHP:** Version 7.4 or 8.x+
* **SQLite3 Extension:** Enabled (Standard in most PHP installations)
* **cURL Extension:** Enabled (Required for the Admin Scanner uptime checks)
* **File Permissions:** The server must have read/write permissions for the application's root directory to generate the `database.sqlite` file and the `/screenshots/` folder.

## 🚀 Installation & Setup

1. **Download/Clone** the repository to your web server (e.g., Apache, Nginx) or local environment (e.g., XAMPP, MAMP, Laravel Valet).
2. **Set the Master Password:**
   * Open `functions.php` in a text editor.
   * Locate the `$MASTER_PASSWORD = 'admin';` variable at the top of the file.
   * Change `'admin'` to a secure password of your choice.
3. **Run the App:** Navigate to the folder in your web browser (e.g., `http://localhost/visual-bookmarks/`).
4. **Auto-Setup:** Upon your first login, the application will automatically create the `database.sqlite` file and the `screenshots` directory if they do not already exist.

## 🖥️ Usage

* **Adding a Bookmark:** Click the "Add Bookmark" button, paste a URL, and assign it to a folder and tags. The server will capture the image in the background.
* **The Bookmarklet:** Drag the "Save to App" button (located above your bookmark grid) to your browser's bookmarks bar. Click it on any website to save the page directly to your app.
* **Admin Scanner:** Click the shield icon in the top navigation bar to open the Admin Dashboard and verify the health of your saved links.

## 🗄️ File Structure

* `index.php` - The main frontend interface and login screen.
* `api.php` - The RESTful backend engine handling CRUD operations, scanning, and importing.
* `functions.php` - Core configuration, SQLite database connection, and scraping utilities.
* `save.php` - The secure endpoint handled by the browser bookmarklet popup.
* `bookmark.png` - The application icon.

## 📝 License
This project is open-source and free to use for personal knowledge management.
