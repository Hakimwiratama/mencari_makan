<?php
require_once __DIR__ . '/config.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
if(empty($_SESSION['user_id'])){header('Location: login.php');exit;}
if(($_SESSION['peran']??'')!=='pemilik'){header('Location: pilih_peran.php');exit;}
$st=$pdo->prepare('SELECT * FROM restaurants WHERE owner_id=?');$st->execute([$_SESSION['user_id']]);$toko=$st->fetch();
if(!$toko){header('Location: profil_penjual.php');exit;}
function uploadMenuFoto($field){
 if(empty($_FILES[$field])||$_FILES[$field]['error']===UPLOAD_ERR_NO_FILE)return null;
 if($_FILES[$field]['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Upload foto gagal.');
 if($_FILES[$field]['size']>5*1024*1024)throw new RuntimeException('Foto maksimal 5 MB.');
 $f=new finfo(FILEINFO_MIME_TYPE);$mime=$f->file($_FILES[$field]['tmp_name']);$map=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
 if(!isset($map[$mime]))throw new RuntimeException('Foto harus JPG, PNG, atau WEBP.');
 $dir=__DIR__.'/uploads';if(!is_dir($dir))mkdir($dir,0755,true);
 $name='menu_'.bin2hex(random_bytes(8)).'.'.$map[$mime];
 if(!move_uploaded_file($_FILES[$field]['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Foto tidak bisa disimpan.');
 return 'uploads/'.$name;
}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $action=$_POST['action']??'simpan';$id=(int)($_POST['id']??0);
  if($action==='hapus'){
   $st=$pdo->prepare('DELETE FROM restaurant_menus WHERE id=? AND restaurant_id=?');$st->execute([$id,$toko['id']]);header('Location: kelola_menu.php');exit;
  }
  if($action==='status'){
   $st=$pdo->prepare('UPDATE restaurant_menus SET tersedia=1-tersedia WHERE id=? AND restaurant_id=?');$st->execute([$id,$toko['id']]);header('Location: kelola_menu.php');exit;
  }
  $nama=trim($_POST['nama_menu']??'');$deskripsi=trim($_POST['deskripsi']??'');$harga=(float)($_POST['harga']??0);
  if($nama==='')throw new RuntimeException('Nama menu wajib diisi.');
  if($harga<0)throw new RuntimeException('Harga tidak boleh negatif.');
  $foto=uploadMenuFoto('foto');
  if($id>0){
   if($foto){$st=$pdo->prepare('UPDATE restaurant_menus SET nama_menu=?,deskripsi=?,harga=?,foto=? WHERE id=? AND restaurant_id=?');$st->execute([$nama,$deskripsi,$harga,$foto,$id,$toko['id']]);}
   else{$st=$pdo->prepare('UPDATE restaurant_menus SET nama_menu=?,deskripsi=?,harga=? WHERE id=? AND restaurant_id=?');$st->execute([$nama,$deskripsi,$harga,$id,$toko['id']]);}
   $menuMessage='diubah';
  }else{$st=$pdo->prepare('INSERT INTO restaurant_menus (restaurant_id,nama_menu,deskripsi,harga,foto) VALUES (?,?,?,?,?)');$st->execute([$toko['id'],$nama,$deskripsi,$harga,$foto]);$menuMessage='ditambahkan';}
  header('Location: detail_toko.php?id='.(int)$toko['id'].'&menu='.$menuMessage);exit;
 }catch(Throwable $ex){$error=$ex->getMessage();}
}
$edit=null;if(isset($_GET['edit'])){$st=$pdo->prepare('SELECT * FROM restaurant_menus WHERE id=? AND restaurant_id=?');$st->execute([(int)$_GET['edit'],$toko['id']]);$edit=$st->fetch()?:null;}
$st=$pdo->prepare('SELECT * FROM restaurant_menus WHERE restaurant_id=? ORDER BY id DESC');$st->execute([$toko['id']]);$menus=$st->fetchAll();
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kelola Menu</title><link rel="stylesheet" href="style.css"><link rel="stylesheet" href="dashboard.css"><style>
body{font-family:Arial,sans-serif;background:#f4f7f6;margin:0;color:#202520}header{background:#0f766e;color:white;padding:22px max(18px,calc((100% - 1100px)/2))}nav{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;padding:12px;border:1px solid #ffffff55;border-radius:12px;background:#ffffff12}nav a{display:inline-block;color:#0f766e;text-decoration:none;padding:10px 13px;border:1px solid rgba(255,255,255,.4);border-radius:8px;background:white;font-size:14px;font-weight:bold;transition:background .2s,transform .2s}nav a:hover{background:#d9f4ee;transform:translateY(-1px)}main{max-width:1000px;margin:24px auto;padding:0 16px}.panel,.card{background:white;border:1px solid #e1e6e1;border-radius:14px;padding:20px;margin-bottom:18px}.field{display:block;font-weight:bold;margin-top:12px}input,textarea{box-sizing:border-box;width:100%;padding:11px;border:1px solid #cbd5ce;border-radius:8px;margin-top:7px;font:inherit}textarea{min-height:80px}button,.btn{display:inline-block;border:0;background:#0f766e;color:white;border-radius:8px;padding:10px 13px;cursor:pointer;text-decoration:none;font-weight:bold;margin-top:10px}button:hover,.btn:hover{background:#115e59}.danger{background:#b42318}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px}.card{padding:0;overflow:hidden}.card img{width:100%;height:180px;object-fit:cover}.body{padding:15px}.price{font-size:18px;font-weight:bold;color:#0f766e}.err{background:#fff0f0;color:#a11;padding:12px;border-radius:8px}.success{background:#e8f5eb;color:#166534;padding:12px;border-radius:8px}
</style></head><body><header class="seller-dashboard-header"><h1>🍲 Kelola Menu</h1><p><?=e($toko['nama'])?></p><?php require __DIR__ . '/seller_navigation.php'; ?></header><main>
<?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?><?php if(($_GET['toko']??'')==='tersimpan'):?><p class="success" role="status">✅ Profil dan lokasi toko berhasil disimpan. Sekarang tambahkan menu makanan di bawah ini.</p><?php endif;?><?php if(isset($_GET['simpan'])):?><p class="success" role="status">✅ Menu berhasil disimpan.</p><?php endif;?>
<section class="panel"><h2><?=$edit?'Edit Menu':'Tambah Menu Baru'?></h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="simpan"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>">
<label class="field">Nama menu *</label><input name="nama_menu" required maxlength="150" value="<?=e($edit['nama_menu']??'')?>">
<label class="field">Deskripsi menu</label><textarea name="deskripsi" placeholder="Jelaskan bahan, rasa, atau ukuran menu"><?=e($edit['deskripsi']??'')?></textarea>
<label class="field">Harga (Rp) *</label><input type="number" name="harga" min="0" step="100" required value="<?=e($edit['harga']??'')?>">
<label class="field">Foto menu (JPG/PNG/WEBP, maksimal 5 MB)</label><input type="file" name="foto" accept="image/jpeg,image/png,image/webp"><?php if(!empty($edit['foto'])):?><p>Foto saat ini:</p><img src="<?=e($edit['foto'])?>" alt="Foto menu" style="width:130px;border-radius:10px"><?php endif;?>
<button type="submit">Simpan Menu</button><?php if($edit):?> <a class="btn" href="kelola_menu.php">Batal</a><?php endif;?></form></section>
<h2>Daftar Menu</h2>
<?php if (!$menus): ?>
    <section class="panel">Belum ada menu. Tambahkan menu pertama di atas.</section>
<?php else: ?>
    <div class="menu-catalog">
        <?php foreach ($menus as $m): ?>
            <article class="menu-product">
                <div class="menu-product-image">
                    <?php if ($m['foto']): ?>
                        <img src="<?= e($m['foto']) ?>" alt="<?= e($m['nama_menu']) ?>">
                    <?php else: ?>
                        <span aria-hidden="true">🍽️</span>
                    <?php endif; ?>
                </div>
                <div class="menu-product-body">
                    <h3><?= e($m['nama_menu']) ?></h3>
                    <p class="menu-product-description">
                        <?= trim((string) $m['deskripsi']) !== ''
                            ? e($m['deskripsi'])
                            : 'Belum ada deskripsi menu.' ?>
                    </p>
                    <p class="menu-product-price">
                        Rp<?= number_format((float) $m['harga'], 0, ',', '.') ?>
                    </p>
                    <p class="menu-product-stock <?= $m['tersedia'] ? '' : 'unavailable' ?>">
                        <?= $m['tersedia'] ? '✓ Tersedia' : 'Tidak tersedia' ?>
                    </p>
                    <div class="menu-product-actions">
                        <a class="btn" href="kelola_menu.php?edit=<?= (int) $m['id'] ?>">Edit</a>
                        <form method="post">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button type="submit">
                                <?= $m['tersedia'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                            </button>
                        </form>
                        <form method="post"
                              onsubmit="return confirm('Yakin hapus menu ini?')">
                            <input type="hidden" name="action" value="hapus">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button class="danger" type="submit">Hapus</button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</main></body></html>
