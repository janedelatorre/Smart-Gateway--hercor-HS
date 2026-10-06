-- =====================================================================
-- MIGRATION V22 PHASE 4.5: IPROG SMS provider message ID
-- =====================================================================
-- For EXISTING installations only. Run once, same as the Phase 3 migration.
--
-- IPROG's send-SMS response includes a "message_id" (e.g. "iSms-XHYBk")
-- that identifies the message on their side for later reference (their
-- GET /sms_messages/status endpoint accepts it). This column just stores
-- that ID alongside the existing sms_logs row -- it is NOT a delivery
-- confirmation, only a reference IPROG issues once they've accepted the
-- request into their own queue.
-- =====================================================================

ALTER TABLE sms_logs
    ADD COLUMN provider_message_id VARCHAR(64) DEFAULT NULL AFTER provider_response;
