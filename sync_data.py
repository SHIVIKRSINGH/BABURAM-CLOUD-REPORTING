from flask import Flask, request, jsonify
import pymysql
import json
import traceback

app = Flask(__name__)

# Central config DB
CENTRAL_DB = {
    "host": "localhost",
    "user": "shivendra",
    "password": "Shiv@24199319bds",
    "database": "softgen_db_central"
}

def get_branch_db_config(branch_id):
    branch_id = branch_id.strip().upper()
    conn = pymysql.connect(**CENTRAL_DB)
    cursor = conn.cursor(pymysql.cursors.DictCursor)
    cursor.execute("SELECT * FROM m_branch_sync_config WHERE UPPER(TRIM(branch_id)) = %s", (branch_id,))
    config = cursor.fetchone()
    cursor.close()
    conn.close()
    return config

def insert_into_branch_db(branch_cfg, table, data_list):
    if not data_list:
        return
    conn = pymysql.connect(
        host=branch_cfg["db_host"],
        user=branch_cfg["db_user"],
        password=branch_cfg["db_password"],
        database=branch_cfg["db_name"]
    )
    cursor = conn.cursor()
    for data in data_list:
        try:
            columns = ", ".join(f"`{col}`" for col in data.keys())
            values = ", ".join(["%s"] * len(data))
            sql = f"REPLACE INTO {table} ({columns}) VALUES ({values})"
            cursor.execute(sql, tuple(data.values()))
        except Exception as e:
            print(f"[ERROR] Failed to insert into {table}: {e}")
            print("Data:", data)
    conn.commit()
    cursor.close()
    conn.close()

@app.route('/sync_data', methods=['POST'])
def sync_data():
    try:
        payload = request.get_json()
        if not payload:
            return jsonify({"status": "error", "msg": "No JSON payload received"}), 400

        branch_id = (
            payload.get("hdr", {}).get("branch_id") or 
            payload.get("hdr", {}).get("branch_code") or 
            payload.get("branch_id")
        )
        if not branch_id:
            return jsonify({"status": "error", "msg": "Missing branch_id"}), 400

        sync_type = payload.get("type")
        hdr = payload.get("hdr", {})
        det = payload.get("det", [])
        pay = payload.get("pay", [])

        db_cfg = get_branch_db_config(branch_id)
        if not db_cfg:
            return jsonify({"status": "error", "msg": f"No DB config for branch {branch_id}"}), 404

        print(f"[SYNC] Type: {sync_type} | Branch: {branch_id}")

        if sync_type == "item":
            insert_into_branch_db(db_cfg, "m_item_hdr", [hdr])
            insert_into_branch_db(db_cfg, "m_item_det", det)
        elif sync_type == "cust":
            insert_into_branch_db(db_cfg, "m_customer", [hdr])
        elif sync_type == "group":
            insert_into_branch_db(db_cfg, "m_group", [hdr])
        elif sync_type == "manuf":
            insert_into_branch_db(db_cfg, "m_manuf", [hdr])
        elif sync_type == "suppp":  # Note: sync_script uses "suppp"
            insert_into_branch_db(db_cfg, "m_supplier", [hdr])
        elif sync_type == "tax_type":
            insert_into_branch_db(db_cfg, "m_tax_type", [hdr])
        elif sync_type == "invoice":
            insert_into_branch_db(db_cfg, "t_invoice_hdr", [hdr])
            insert_into_branch_db(db_cfg, "t_invoice_det", det)
            insert_into_branch_db(db_cfg, "t_invoice_pay_det", pay)
        elif sync_type == "purchase":
            insert_into_branch_db(db_cfg, "t_receipt_hdr", [hdr])
            insert_into_branch_db(db_cfg, "t_receipt_det", det)
            insert_into_branch_db(db_cfg, "t_receipt_charges", pay)
        else:
            return jsonify({"status": "error", "msg": f"Unknown sync type: {sync_type}"}), 400

        return jsonify({"status": "success"}), 200

    except Exception as e:
        print("[ERROR] Exception during /sync_data")
        print(traceback.format_exc())
        return jsonify({"status": "error", "msg": str(e)}), 500

if __name__ == '__main__':
    print("=== Running sync_data.py on port 5052 ===")
    app.run(host="0.0.0.0", port=5052)
