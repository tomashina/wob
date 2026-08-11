<?php
// config baze
$db_host = 'localhost';
$db_user = 'dmb_wob';
$db_pass = 'ZKgdRp=4[fdS';
$db_name = 'dmb_wob';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Greška pri spajanju na bazu: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// učitaj XML feed
$xml = simplexml_load_file('https://b2b.activeshop.com.pl/media/productsfeed/b2b-eng.xml');
if (!$xml) {
    die("Neuspjelo učitavanje XML-a.");
}

// obradi svaki item iz XML-a
foreach ($xml->item as $item) {
    $sku = trim((string)$item->sku);     // ✅ koristi <sku> iz XML-a
    $quantity = (int)$item->qty;         // ✅ koristi <qty>
    $price = (float)$item->price;        // ✅ koristi <price>
    $weight = isset($item->weight) ? (float)$item->weight : 0;  // ✅ koristi <weight> ako postoji

    // ako je težina iznad 2 kg, koristi 1.4, inače 1.2
    if ($weight > 2) {
        $price = $price * 1.4;
    } else {
        $price = $price * 1.2;
    }

    // pronađi proizvod prema SKU-u
    $query = $conn->query("SELECT product_id FROM oc_product WHERE sku = '$sku'");

    if ($query && $query->num_rows > 0) {
        $row = $query->fetch_assoc();
        $product_id = $row['product_id'];

        // postavi status: 1 ako ima na skladištu, inače 0
        $status = ($quantity > 0) ? 1 : 0;

        // ažuriraj proizvod
        $sql = "
            UPDATE oc_product 
            SET quantity = $quantity, price = $price, status = $status 
            WHERE product_id = $product_id
        ";
        $conn->query($sql);

        echo "✅ Ažuriran SKU $sku → Qty: $quantity | Težina: $weight kg | Cijena: $price | Status: $status<br>";
    } else {
        echo "⚠️ Nema proizvoda s SKU: $sku<br>";
    }
}

$conn->close();
?>
