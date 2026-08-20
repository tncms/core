---
title: Giới thiệu
description: Giới thiệu tổng quan về TNCMS.
order: 1
---

# Giới thiệu

## TN CMS là gì?

TN CMS là một hệ quản trị nội dung (CMS) mã nguồn mở được xây dựng trên Laravel, hướng tới sự đơn giản, dễ mở rộng và ổn định trong quá trình vận hành lâu dài.

Dự án được phát triển với mục tiêu tạo ra một nền tảng đủ linh hoạt để xây dựng nhiều loại website khác nhau như:

- Website doanh nghiệp
- Blog cá nhân hoặc chuyên trang nội dung
- Tạp chí điện tử
- Website giới thiệu dịch vụ
- Website thương mại điện tử thông qua plugin
- Các hệ thống mở rộng theo nhu cầu riêng

TN CMS không cố gắng thay thế mọi CMS khác hay trở thành sản phẩm lớn nhất thị trường. Mục tiêu của dự án là trở thành một công cụ hữu ích, ổn định và dễ sử dụng cho cả nhà phát triển lẫn người quản trị website.

---

## Nền tảng cốt lõi

TN CMS được xây dựng dựa trên các công nghệ hiện đại và phổ biến:

- Laravel làm nền tảng backend
- Filament cho hệ thống quản trị
- Hệ thống Plugin độc lập giúp mở rộng tính năng
- Hệ thống Theme tách biệt giao diện với lõi
- Hỗ trợ đa ngôn ngữ
- Media Manager quản lý tập tin tập trung
- SEO cơ bản tích hợp sẵn
- Demo Importer giúp triển khai nhanh website mẫu

Kiến trúc của hệ thống được thiết kế theo hướng:

- Hạn chế phụ thuộc vào mã nguồn bên thứ ba
- Giảm việc sửa trực tiếp vào vendor
- Giữ lõi ổn định và dễ nâng cấp
- Ưu tiên khả năng mở rộng bằng Plugin và Theme

---

## Mục tiêu trong tương lai

TN CMS được phát triển với định hướng lâu dài.

### Duy trì hiệu năng ổn định

Nhiều CMS hoạt động tốt ở giai đoạn đầu nhưng dần trở nên chậm chạp khi số lượng plugin, theme và dữ liệu tăng lên.

TN CMS hướng tới việc:

- Giữ cấu trúc lõi gọn gàng
- Giảm tải các thành phần không cần thiết
- Hạn chế truy vấn dư thừa
- Duy trì hiệu năng ổn định khi hệ thống phát triển

### Nâng cấp dễ dàng

Một trong những vấn đề phổ biến của CMS là:

- Cập nhật phiên bản dễ phát sinh lỗi
- Plugin hoặc Theme bị mất tương thích
- Sửa đổi lõi gây khó khăn cho việc nâng cấp

TN CMS cố gắng giảm thiểu các vấn đề này bằng cách:

- Tách biệt Core, Plugin và Theme
- Hạn chế chỉnh sửa trực tiếp mã nguồn bên thứ ba
- Xây dựng API và Extension Point rõ ràng
- Giữ khả năng tương thích ngược khi có thể

### Xây dựng hệ sinh thái mở

Dự án hướng tới việc xây dựng:

- Hệ thống Plugin phong phú
- Theme dễ phát triển
- Tài liệu đầy đủ
- Công cụ hỗ trợ nhà phát triển
- Quy trình cài đặt và cập nhật đơn giản

---

## Ưu điểm

### Kiến trúc tách biệt

Core, Plugin và Theme được thiết kế độc lập, giúp việc phát triển và bảo trì dễ dàng hơn.

### Dễ mở rộng

Nhà phát triển có thể xây dựng Plugin hoặc Theme mới mà không cần thay đổi mã nguồn lõi.

### Dễ nâng cấp

Hạn chế sửa đổi trực tiếp vào vendor hoặc framework gốc, giúp giảm rủi ro khi cập nhật.

### Quản trị hiện đại

Sử dụng Filament làm nền tảng quản trị với giao diện hiện đại và trải nghiệm tốt.

### Mã nguồn mở

Người dùng có thể tự do sử dụng, nghiên cứu và đóng góp cho dự án.

---

## Nhược điểm

### Dự án còn đang phát triển

Một số tính năng có thể chưa đầy đủ hoặc còn thay đổi trong các phiên bản tiếp theo.

### Hệ sinh thái còn nhỏ

Số lượng Plugin, Theme và tài liệu hiện chưa thể so sánh với các CMS đã phát triển lâu năm.

### Ưu tiên sự ổn định hơn số lượng tính năng

TN CMS không cố gắng tích hợp quá nhiều tính năng vào lõi. Một số nhu cầu đặc biệt có thể cần cài đặt thêm Plugin hoặc tự phát triển mở rộng.

---

## Triết lý phát triển

TN CMS không đặt mục tiêu trở thành CMS lớn nhất hay nhiều tính năng nhất.

Dự án được xây dựng với mong muốn tạo ra một nền tảng:

- Dễ sử dụng
- Dễ mở rộng
- Dễ nâng cấp
- Hoạt động ổn định trong thời gian dài
- Mang lại giá trị thực tế cho người sử dụng

Nếu TN CMS có thể giúp bạn xây dựng và vận hành website dễ dàng hơn, thì đó chính là mục tiêu mà dự án hướng tới.
