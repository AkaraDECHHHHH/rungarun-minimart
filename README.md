# รุ่งอรุณ มินิมาร์ท (Rungarun Minimart)

ระบบจัดการร้านมินิมาร์ทที่มีหน้าร้านค้า (storefront) และระบบหลังร้าน (backoffice)

## ฟีเจอร์

- 🛒 หน้าร้านค้าสำหรับลูกค้า
- 👥 ระบบสมาชิกและสะสมแต้ม
- 🎁 ระบบโปรโมชั่น
- 📦 จัดการสินค้าและรูปภาพ
- 💳 ระบบชำระเงินและใบเสร็จ
- 📊 รายงานยอดขาย
- 🚚 ระบบสั่งซื้อสินค้า
- 🏬 จัดการโกดัง

## เทคโนโลยี

- PHP (Pure PHP, no framework)
- MySQL
- HTML/CSS/JavaScript (Vanilla)
- Apache

## การติดตั้ง (Local)

1. ติดตั้ง XAMPP
2. นำโฟลเดอร์นี้ไปวางใน `C:\xampp\htdocs\rungarun\`
3. เปิด XAMPP Control Panel และเริ่ม Apache และ MySQL
4. เปิด phpMyAdmin และ import ไฟล์ `database.sql`
5. เข้าใช้งานที่:
   - Storefront: http://localhost/rungarun/storefront.html
   - Backoffice: http://localhost/rungarun/backoffice.html

## การ Deploy

### Railway
1. Push โค้ดไปที่ GitHub
2. ไปที่ https://railway.app/new
3. เลือก repository นี้
4. Railway จะ detect PHP project และ setup ให้อัตโนมัติ
5. เพิ่ม MySQL service
6. ตั้งค่า environment variables ใน Railway:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
7. Deploy

### Environment Variables
สำหรับ production ต้องตั้งค่า environment variables ดังนี้:
- `DB_HOST` - Database host
- `DB_NAME` - Database name
- `DB_USER` - Database username
- `DB_PASS` - Database password

## License

MIT
