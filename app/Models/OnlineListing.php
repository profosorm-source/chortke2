<?php
namespace App\Models;
use Core\Model;

class OnlineListing extends Model
{
    public function find(int $id): ?object {
        $s = $this->db->prepare("SELECT ol.*,s.full_name as seller_name,b.full_name as buyer_name FROM online_listings ol LEFT JOIN users s ON s.id=ol.seller_id LEFT JOIN users b ON b.id=ol.buyer_id WHERE ol.id=? AND ol.deleted_at IS NULL");
        $s->execute([$id]); return $s->fetch(\PDO::FETCH_OBJ)?:null;
    }
    public function create(array $d): ?object {
        $s = $this->db->prepare("INSERT INTO online_listings(seller_id,platform,page_url,username,title,description,member_count,creation_date,price_usdt,proof_text,screenshots,status,bio_verified)VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending_verification',0)");
        $ok = $s->execute([$d['seller_id'],$d['platform'],$d['page_url'],$d['username'],$d['title'],$d['description'],$d['member_count']??0,$d['creation_date']??null,$d['price_usdt'],$d['proof_text']??null,$d['screenshots']??null]);
        return $ok?$this->find((int)$this->db->lastInsertId()):null;
    }
    public function updateStatus(int $id, string $status, array $extra=[]): bool {
        $set=['status=?']; $vals=[$status];
        foreach(['bio_verified','buyer_id','admin_note','rejection_reason'] as $f) if(array_key_exists($f,$extra)){$set[]="$f=?";$vals[]=$extra[$f];}
        $vals[]=$id; return $this->db->prepare("UPDATE online_listings SET ".implode(',',$set)." WHERE id=?")->execute($vals);
    }
    public function getActive(array $f=[], int $limit=20, int $offset=0): array {
        $where=["ol.status='active'","ol.deleted_at IS NULL"]; $p=[];
        if(!empty($f['platform'])){$where[]="ol.platform=?";$p[]=$f['platform'];}
        if(!empty($f['search'])){$where[]="(ol.title LIKE ? OR ol.username LIKE ?)";$s='%'.$f['search'].'%';$p[]=$s;$p[]=$s;}
        $p[]=$limit;$p[]=$offset;
        $stmt=$this->db->prepare("SELECT ol.*,s.full_name as seller_name FROM online_listings ol LEFT JOIN users s ON s.id=ol.seller_id WHERE ".implode(' AND ',$where)." ORDER BY ol.created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute($p); return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
    public function getBySeller(int $uid, int $limit=30, int $offset=0): array {
        $s=$this->db->prepare("SELECT ol.*,b.full_name as buyer_name FROM online_listings ol LEFT JOIN users b ON b.id=ol.buyer_id WHERE ol.seller_id=? AND ol.deleted_at IS NULL ORDER BY ol.created_at DESC LIMIT ? OFFSET ?");
        $s->execute([$uid,$limit,$offset]); return $s->fetchAll(\PDO::FETCH_OBJ);
    }
    public function getByBuyer(int $uid, int $limit=30, int $offset=0): array {
        $s=$this->db->prepare("SELECT ol.*,s.full_name as seller_name FROM online_listings ol LEFT JOIN users s ON s.id=ol.seller_id WHERE ol.buyer_id=? AND ol.deleted_at IS NULL ORDER BY ol.updated_at DESC LIMIT ? OFFSET ?");
        $s->execute([$uid,$limit,$offset]); return $s->fetchAll(\PDO::FETCH_OBJ);
    }
    public function adminList(array $f=[], int $limit=30, int $offset=0): array {
        $where=["ol.deleted_at IS NULL"]; $p=[];
        if(!empty($f['status'])){$where[]="ol.status=?";$p[]=$f['status'];}
        $p[]=$limit;$p[]=$offset;
        $s=$this->db->prepare("SELECT ol.*,s.full_name as seller_name,b.full_name as buyer_name FROM online_listings ol LEFT JOIN users s ON s.id=ol.seller_id LEFT JOIN users b ON b.id=ol.buyer_id WHERE ".implode(' AND ',$where)." ORDER BY ol.created_at DESC LIMIT ? OFFSET ?");
        $s->execute($p); return $s->fetchAll(\PDO::FETCH_OBJ);
    }
    public function adminCount(array $f=[]): int {
        $where=["deleted_at IS NULL"]; $p=[];
        if(!empty($f['status'])){$where[]="status=?";$p[]=$f['status'];}
        $s=$this->db->prepare("SELECT COUNT(*) FROM online_listings WHERE ".implode(' AND ',$where));
        $s->execute($p); return (int)$s->fetchColumn();
    }
    public function platforms(): array { return ['instagram'=>'اینستاگرام','telegram'=>'تلگرام','youtube'=>'یوتیوب','twitter'=>'توییتر/X','tiktok'=>'تیک‌تاک','other'=>'سایر']; }
    public function statuses(): array { return ['pending_verification'=>'در انتظار تایید بیو','active'=>'فعال','sold'=>'فروخته شده','in_escrow'=>'در حال انتقال','disputed'=>'اختلاف','rejected'=>'رد شده','cancelled'=>'لغو']; }
}
