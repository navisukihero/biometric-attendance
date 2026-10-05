-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: ucchr_system_recovered
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `module` varchar(80) NOT NULL DEFAULT 'System',
  `record_id` varchar(80) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `old_values` longtext DEFAULT NULL,
  `new_values` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_activity_user` (`user_id`),
  KEY `idx_activity_module_date` (`module`,`created_at`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=494 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (36,1,'Queued fingerprint enrollment for employee #1 in slot 1','System',NULL,'Queued fingerprint enrollment for employee #1 in slot 1',NULL,NULL,NULL,'2026-08-22 07:11:30'),(37,1,'Failed fingerprint enrollment for employee #1: Enrollment scan timeout','System',NULL,'Failed fingerprint enrollment for employee #1: Enrollment scan timeout',NULL,NULL,NULL,'2026-08-22 07:11:52'),(38,1,'Queued fingerprint enrollment for employee #1 in slot 1','System',NULL,'Queued fingerprint enrollment for employee #1 in slot 1',NULL,NULL,NULL,'2026-08-22 07:12:01'),(39,1,'Completed fingerprint enrollment for employee #1: Enrolled after two matching scans','System',NULL,'Completed fingerprint enrollment for employee #1: Enrolled after two matching scans',NULL,NULL,NULL,'2026-08-22 07:12:08'),(40,1,'Edited employee record - Jules Ivan Zafra','System',NULL,'Edited employee record - Jules Ivan Zafra',NULL,NULL,NULL,'2026-08-22 07:12:17'),(41,1,'Queued fingerprint enrollment for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-22 07:12:24'),(42,1,'Completed fingerprint enrollment for employee #11: Enrolled after two matching scans','System',NULL,'Completed fingerprint enrollment for employee #11: Enrolled after two matching scans',NULL,NULL,NULL,'2026-08-22 07:12:32'),(43,1,'Edited employee record - Grey Zafra','System',NULL,'Edited employee record - Grey Zafra',NULL,NULL,NULL,'2026-08-22 07:12:43'),(44,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-22 07:12:49'),(45,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-22 07:13:22'),(46,1,'Queued fingerprint enrollment for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-22 07:14:03'),(47,1,'Queued fingerprint enrollment for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-22 07:14:24'),(48,1,'Saved Monday schedule for EMP-1002','System',NULL,'Saved Monday schedule for EMP-1002',NULL,NULL,NULL,'2026-08-22 07:18:09'),(49,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-22 07:22:42'),(50,1,'Failed fingerprint enrollment for employee #11: Enrollment scan timeout','System',NULL,'Failed fingerprint enrollment for employee #11: Enrollment scan timeout',NULL,NULL,NULL,'2026-08-22 07:23:03'),(51,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-22 07:28:00'),(52,1,'Queued fingerprint enrollment for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-22 07:28:15'),(53,1,'Completed fingerprint enrollment for employee #11: Enrolled after two matching scans','System',NULL,'Completed fingerprint enrollment for employee #11: Enrolled after two matching scans',NULL,NULL,NULL,'2026-08-22 07:28:23'),(54,1,'Edited employee record - Grey Zafra','System',NULL,'Edited employee record - Grey Zafra',NULL,NULL,NULL,'2026-08-22 07:28:31'),(55,1,'Edited employee record - Grey Zafra','System',NULL,'Edited employee record - Grey Zafra',NULL,NULL,NULL,'2026-08-22 07:42:12'),(56,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-22 21:34:49'),(57,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-22 21:35:07'),(58,NULL,'Failed two-scan attendance verification for employee #11: Wrong employee on scan 1','System',NULL,'Failed two-scan attendance verification for employee #11: Wrong employee on scan 1',NULL,NULL,NULL,'2026-08-22 21:39:54'),(59,NULL,'Failed two-scan attendance verification for employee #11: Two scans did not match','System',NULL,'Failed two-scan attendance verification for employee #11: Two scans did not match',NULL,NULL,NULL,'2026-08-22 21:40:21'),(60,NULL,'Completed two-scan attendance verification for employee #1: Verified twice. Time-out recorded at 9:40 PM.','System',NULL,'Completed two-scan attendance verification for employee #1: Verified twice. Time-out recorded at 9:40 PM.',NULL,NULL,NULL,'2026-08-22 21:40:43'),(61,NULL,'Failed two-scan attendance verification for employee #11: Wrong employee on scan 1','System',NULL,'Failed two-scan attendance verification for employee #11: Wrong employee on scan 1',NULL,NULL,NULL,'2026-08-22 21:41:01'),(62,NULL,'Failed two-scan attendance verification for employee #11: Two scans did not match','System',NULL,'Failed two-scan attendance verification for employee #11: Two scans did not match',NULL,NULL,NULL,'2026-08-22 21:41:22'),(63,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-22 21:42:49'),(64,1,'Saved Monday schedule for EMP-1002','System',NULL,'Saved Monday schedule for EMP-1002',NULL,NULL,NULL,'2026-08-22 21:50:20'),(65,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-22 21:52:50'),(66,NULL,'Completed two-scan attendance verification for employee #1: Verified twice. Time-in already recorded at 7:13 AM.','System',NULL,'Completed two-scan attendance verification for employee #1: Verified twice. Time-in already recorded at 7:13 AM.',NULL,NULL,NULL,'2026-08-22 22:27:25'),(67,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-22 22:27:50'),(68,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-22 23:11:00'),(69,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-22 23:11:26'),(70,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-22 23:11:48'),(80,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-22 23:56:03'),(81,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-22 23:56:22'),(93,1,'Repaired missing password credential using a secure hash','System',NULL,'Repaired missing password credential using a secure hash',NULL,NULL,NULL,'2026-08-23 09:18:08'),(98,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 09:26:02'),(99,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-23 09:29:09'),(100,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 09:29:26'),(101,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-23 09:30:16'),(102,1,'Changed password after verifying the current password','System',NULL,'Changed password after verifying the current password',NULL,NULL,NULL,'2026-08-23 09:31:36'),(105,1,'Changed password after verifying the current password','System',NULL,'Changed password after verifying the current password',NULL,NULL,NULL,'2026-08-23 09:40:34'),(106,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 09:40:55'),(107,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-23 09:59:42'),(108,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 10:00:22'),(109,1,'Added employee record - Johnrel Rojo','System',NULL,'Added employee record - Johnrel Rojo',NULL,NULL,NULL,'2026-08-23 10:14:30'),(110,1,'Queued fingerprint enrollment v1 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v1 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:14:52'),(111,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v1: Finger already belongs to slot 3','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v1: Finger already belongs to slot 3',NULL,NULL,NULL,'2026-08-23 10:14:59'),(112,1,'Queued fingerprint enrollment v2 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v2 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:15:15'),(113,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v2: Finger already belongs to slot 3','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v2: Finger already belongs to slot 3',NULL,NULL,NULL,'2026-08-23 10:15:20'),(114,1,'Queued fingerprint enrollment v3 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v3 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:15:27'),(115,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v3: Finger already belongs to slot 3','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v3: Finger already belongs to slot 3',NULL,NULL,NULL,'2026-08-23 10:15:36'),(116,1,'Queued fingerprint enrollment v4 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v4 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:15:45'),(117,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v4: Finger already belongs to slot 3','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v4: Finger already belongs to slot 3',NULL,NULL,NULL,'2026-08-23 10:15:51'),(118,1,'Queued fingerprint enrollment v5 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v5 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:16:02'),(119,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v5: Reloaded fingerprint could not be searched','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v5: Reloaded fingerprint could not be searched',NULL,NULL,NULL,'2026-08-23 10:16:09'),(120,1,'Queued fingerprint enrollment v6 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v6 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:16:17'),(121,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v6: Reloaded fingerprint could not be searched','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v6: Reloaded fingerprint could not be searched',NULL,NULL,NULL,'2026-08-23 10:16:25'),(122,1,'Queued fingerprint enrollment v7 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v7 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:16:31'),(123,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v7: Reloaded fingerprint could not be searched','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v7: Reloaded fingerprint could not be searched',NULL,NULL,NULL,'2026-08-23 10:16:40'),(124,1,'Edited employee record - Johnrel Rojo','System',NULL,'Edited employee record - Johnrel Rojo',NULL,NULL,NULL,'2026-08-23 10:16:51'),(125,1,'Queued fingerprint enrollment v8 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v8 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:17:08'),(126,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v8: Reloaded fingerprint could not be searched','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v8: Reloaded fingerprint could not be searched',NULL,NULL,NULL,'2026-08-23 10:17:15'),(127,1,'Queued fingerprint enrollment v9 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v9 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:18:40'),(128,1,'Queued fingerprint enrollment v10 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v10 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:18:44'),(129,1,'Queued fingerprint enrollment v11 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v11 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:18:49'),(130,1,'Queued fingerprint enrollment v12 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v12 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:18:58'),(131,1,'Queued fingerprint enrollment v13 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v13 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 10:19:05'),(132,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 19:15:17'),(133,1,'Queued fingerprint enrollment v14 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v14 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 19:21:12'),(134,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-23 19:21:20'),(135,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 19:21:47'),(136,1,'Queued fingerprint enrollment v15 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v15 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 19:22:32'),(141,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-23 20:03:44'),(142,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 20:05:28'),(143,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v15: Enrollment cancelled at biometric terminal by BTN2','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v15: Enrollment cancelled at biometric terminal by BTN2',NULL,NULL,NULL,'2026-08-23 20:06:16'),(144,1,'Queued fingerprint enrollment v16 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v16 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 20:06:35'),(145,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v16: Finger already belongs to slot 3','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v16: Finger already belongs to slot 3',NULL,NULL,NULL,'2026-08-23 20:06:46'),(146,1,'Queued fingerprint enrollment v17 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v17 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 20:07:02'),(147,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v17: Reloaded fingerprint could not be searched','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v17: Reloaded fingerprint could not be searched',NULL,NULL,NULL,'2026-08-23 20:07:13'),(148,1,'Queued fingerprint enrollment v18 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v18 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 20:07:20'),(149,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v18: Reloaded fingerprint could not be searched','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v18: Reloaded fingerprint could not be searched',NULL,NULL,NULL,'2026-08-23 20:07:29'),(150,1,'Queued fingerprint enrollment v19 for employee #22 in slot 22','System',NULL,'Queued fingerprint enrollment v19 for employee #22 in slot 22',NULL,NULL,NULL,'2026-08-23 20:19:27'),(151,1,'Failed fingerprint enrollment for employee #22, slot 22, mapping v19: Enrollment scans mismatch','System',NULL,'Failed fingerprint enrollment for employee #22, slot 22, mapping v19: Enrollment scans mismatch',NULL,NULL,NULL,'2026-08-23 20:19:41'),(152,1,'Added employee record - Rafael Tutor','System',NULL,'Added employee record - Rafael Tutor',NULL,NULL,NULL,'2026-08-23 20:22:13'),(153,1,'Queued fingerprint enrollment v1 for employee #28 in slot 28','System',NULL,'Queued fingerprint enrollment v1 for employee #28 in slot 28',NULL,NULL,NULL,'2026-08-23 20:22:30'),(154,1,'Failed fingerprint enrollment for employee #28, slot 28, mapping v1: Finger already belongs to slot 4','System',NULL,'Failed fingerprint enrollment for employee #28, slot 28, mapping v1: Finger already belongs to slot 4',NULL,NULL,NULL,'2026-08-23 20:22:40'),(155,1,'Queued fingerprint enrollment v2 for employee #28 in slot 28','System',NULL,'Queued fingerprint enrollment v2 for employee #28 in slot 28',NULL,NULL,NULL,'2026-08-23 20:22:46'),(156,1,'Failed fingerprint enrollment for employee #28, slot 28, mapping v2: Finger already belongs to slot 4','System',NULL,'Failed fingerprint enrollment for employee #28, slot 28, mapping v2: Finger already belongs to slot 4',NULL,NULL,NULL,'2026-08-23 20:22:56'),(165,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-23 21:08:09'),(166,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 21:08:20'),(167,1,'Queued fingerprint enrollment v3 for employee #28 in slot 28','System',NULL,'Queued fingerprint enrollment v3 for employee #28 in slot 28',NULL,NULL,NULL,'2026-08-23 21:10:00'),(168,1,'Completed fingerprint enrollment for employee #28, slot 28, mapping v3: Enrolled and verified at slot 28','System',NULL,'Completed fingerprint enrollment for employee #28, slot 28, mapping v3: Enrolled and verified at slot 28',NULL,NULL,NULL,'2026-08-23 21:10:26'),(169,1,'Edited employee record - Rafael Tutor','System',NULL,'Edited employee record - Rafael Tutor',NULL,NULL,NULL,'2026-08-23 21:10:41'),(170,1,'Edited employee record - Navi Sukihero','System',NULL,'Edited employee record - Navi Sukihero',NULL,NULL,NULL,'2026-08-23 21:12:08'),(171,1,'Failed two-scan attendance verification for employee #28: Fingerprint not enrolled','System',NULL,'Failed two-scan attendance verification for employee #28: Fingerprint not enrolled',NULL,NULL,NULL,'2026-08-23 21:12:46'),(172,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-23 21:13:01'),(173,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 21:13:36'),(174,1,'Queued fingerprint enrollment v4 for employee #28 in slot 28','System',NULL,'Queued fingerprint enrollment v4 for employee #28 in slot 28',NULL,NULL,NULL,'2026-08-23 21:13:46'),(175,1,'Completed fingerprint enrollment for employee #28, slot 28, mapping v4: Enrolled and verified at slot 28','System',NULL,'Completed fingerprint enrollment for employee #28, slot 28, mapping v4: Enrolled and verified at slot 28',NULL,NULL,NULL,'2026-08-23 21:14:01'),(176,1,'Edited employee record - Navi Sukihero','System',NULL,'Edited employee record - Navi Sukihero',NULL,NULL,NULL,'2026-08-23 21:14:08'),(177,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-23 21:19:11'),(178,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-23 21:26:52'),(185,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-26 22:30:01'),(186,1,'Queued fingerprint enrollment v1 for employee #1 in slot 1','System',NULL,'Queued fingerprint enrollment v1 for employee #1 in slot 1',NULL,NULL,NULL,'2026-08-26 22:32:21'),(187,1,'Completed fingerprint enrollment for employee #1, slot 1, mapping v1: Five thumb positions enrolled for profile 1','System',NULL,'Completed fingerprint enrollment for employee #1, slot 1, mapping v1: Five thumb positions enrolled for profile 1',NULL,NULL,NULL,'2026-08-26 22:34:27'),(188,1,'Edited employee record - Jules Ivan Zafra','System',NULL,'Edited employee record - Jules Ivan Zafra',NULL,NULL,NULL,'2026-08-26 22:36:02'),(189,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-27 06:29:04'),(192,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-08-27 06:44:38'),(193,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-27 06:49:26'),(194,1,'Removed Monday schedule override for EMP-1002','System',NULL,'Removed Monday schedule override for EMP-1002',NULL,NULL,NULL,'2026-08-27 06:54:10'),(195,1,'Removed Monday schedule override for EMP-0010','System',NULL,'Removed Monday schedule override for EMP-0010',NULL,NULL,NULL,'2026-08-27 06:54:27'),(196,1,'Removed Tuesday schedule override for EMP-0010','System',NULL,'Removed Tuesday schedule override for EMP-0010',NULL,NULL,NULL,'2026-08-27 06:54:33'),(197,1,'Removed Wednesday schedule override for EMP-0010','System',NULL,'Removed Wednesday schedule override for EMP-0010',NULL,NULL,NULL,'2026-08-27 06:54:36'),(198,1,'Removed Thursday schedule override for EMP-0010','System',NULL,'Removed Thursday schedule override for EMP-0010',NULL,NULL,NULL,'2026-08-27 06:54:40'),(199,1,'Removed Friday schedule override for EMP-0010','System',NULL,'Removed Friday schedule override for EMP-0010',NULL,NULL,NULL,'2026-08-27 06:54:43'),(200,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-27 11:54:50'),(201,1,'Assigned complete weekly schedule to EMP-1012','System',NULL,'Assigned complete weekly schedule to EMP-1012',NULL,NULL,NULL,'2026-08-27 11:56:59'),(202,1,'Assigned complete weekly schedule to EMP-1002','System',NULL,'Assigned complete weekly schedule to EMP-1002',NULL,NULL,NULL,'2026-08-27 11:57:11'),(203,1,'Assigned complete weekly schedule to EMP-0010','System',NULL,'Assigned complete weekly schedule to EMP-0010',NULL,NULL,NULL,'2026-08-27 11:57:18'),(204,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-27 18:04:26'),(207,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-27 22:53:05'),(208,1,'Queued fingerprint enrollment v2 for employee #1 in slot 1','System',NULL,'Queued fingerprint enrollment v2 for employee #1 in slot 1',NULL,NULL,NULL,'2026-08-27 22:54:37'),(209,1,'Failed fingerprint enrollment for employee #1, slot 1, mapping v2: Could not check existing fingerprints; partial sensor profile cleared','System',NULL,'Failed fingerprint enrollment for employee #1, slot 1, mapping v2: Could not check existing fingerprints; partial sensor profile cleared',NULL,NULL,NULL,'2026-08-27 22:55:10'),(210,1,'Queued fingerprint enrollment v3 for employee #1 in slot 1','System',NULL,'Queued fingerprint enrollment v3 for employee #1 in slot 1',NULL,NULL,NULL,'2026-08-27 22:55:21'),(211,1,'Failed fingerprint enrollment for employee #1, slot 1, mapping v3: Could not check existing fingerprints; partial sensor profile cleared','System',NULL,'Failed fingerprint enrollment for employee #1, slot 1, mapping v3: Could not check existing fingerprints; partial sensor profile cleared',NULL,NULL,NULL,'2026-08-27 22:56:00'),(212,1,'Queued fingerprint enrollment v4 for employee #1 in slot 1','System',NULL,'Queued fingerprint enrollment v4 for employee #1 in slot 1',NULL,NULL,NULL,'2026-08-27 22:56:07'),(213,1,'Completed fingerprint enrollment for employee #1, slot 1, mapping v4: Five thumb positions enrolled and verified for profile 1','System',NULL,'Completed fingerprint enrollment for employee #1, slot 1, mapping v4: Five thumb positions enrolled and verified for profile 1',NULL,NULL,NULL,'2026-08-27 22:57:04'),(214,1,'Queued fingerprint enrollment v5 for employee #1 in slot 1','System',NULL,'Queued fingerprint enrollment v5 for employee #1 in slot 1',NULL,NULL,NULL,'2026-08-27 22:57:50'),(215,1,'Failed fingerprint enrollment for employee #1, slot 1, mapping v5: Enrollment scan timeout','System',NULL,'Failed fingerprint enrollment for employee #1, slot 1, mapping v5: Enrollment scan timeout',NULL,NULL,NULL,'2026-08-27 22:58:16'),(216,1,'Queued fingerprint enrollment v6 for employee #1 in slot 1','System',NULL,'Queued fingerprint enrollment v6 for employee #1 in slot 1',NULL,NULL,NULL,'2026-08-27 22:58:32'),(217,1,'Failed fingerprint enrollment for employee #1, slot 1, mapping v6: Could not check existing fingerprints','System',NULL,'Failed fingerprint enrollment for employee #1, slot 1, mapping v6: Could not check existing fingerprints',NULL,NULL,NULL,'2026-08-27 22:58:46'),(218,1,'Queued fingerprint enrollment v7 for employee #1 in slot 1','System',NULL,'Queued fingerprint enrollment v7 for employee #1 in slot 1',NULL,NULL,NULL,'2026-08-27 22:58:52'),(219,1,'Completed fingerprint enrollment for employee #1, slot 1, mapping v7: Five thumb positions enrolled and verified for profile 1','System',NULL,'Completed fingerprint enrollment for employee #1, slot 1, mapping v7: Five thumb positions enrolled and verified for profile 1',NULL,NULL,NULL,'2026-08-27 22:59:47'),(220,1,'Edited employee record - Jules Ivan Zafra','System',NULL,'Edited employee record - Jules Ivan Zafra',NULL,NULL,NULL,'2026-08-27 23:00:09'),(221,1,'Queued fingerprint enrollment v1 for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment v1 for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-27 23:00:14'),(222,1,'Failed fingerprint enrollment for employee #11, slot 11, mapping v1: Could not check existing fingerprints; partial sensor profile cleared','System',NULL,'Failed fingerprint enrollment for employee #11, slot 11, mapping v1: Could not check existing fingerprints; partial sensor profile cleared',NULL,NULL,NULL,'2026-08-27 23:00:35'),(223,1,'Queued fingerprint enrollment v2 for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment v2 for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-27 23:00:41'),(224,1,'Failed fingerprint enrollment for employee #11, slot 11, mapping v2: Could not check existing fingerprints; partial sensor profile cleared','System',NULL,'Failed fingerprint enrollment for employee #11, slot 11, mapping v2: Could not check existing fingerprints; partial sensor profile cleared',NULL,NULL,NULL,'2026-08-27 23:01:04'),(225,1,'Queued fingerprint enrollment v3 for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment v3 for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-27 23:01:10'),(226,1,'Failed fingerprint enrollment for employee #11, slot 11, mapping v3: Could not check existing fingerprints; partial sensor profile cleared','System',NULL,'Failed fingerprint enrollment for employee #11, slot 11, mapping v3: Could not check existing fingerprints; partial sensor profile cleared',NULL,NULL,NULL,'2026-08-27 23:01:57'),(227,1,'Queued fingerprint enrollment v4 for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment v4 for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-27 23:02:03'),(228,1,'Failed fingerprint enrollment for employee #11, slot 11, mapping v4: Could not check existing fingerprints','System',NULL,'Failed fingerprint enrollment for employee #11, slot 11, mapping v4: Could not check existing fingerprints',NULL,NULL,NULL,'2026-08-27 23:02:13'),(229,1,'Queued fingerprint enrollment v5 for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment v5 for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-27 23:02:18'),(230,1,'Failed fingerprint enrollment for employee #11, slot 11, mapping v5: Could not check existing fingerprints; partial sensor profile cleared','System',NULL,'Failed fingerprint enrollment for employee #11, slot 11, mapping v5: Could not check existing fingerprints; partial sensor profile cleared',NULL,NULL,NULL,'2026-08-27 23:02:55'),(231,1,'Queued fingerprint enrollment v6 for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment v6 for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-27 23:03:01'),(232,1,'Failed fingerprint enrollment for employee #11, slot 11, mapping v6: Could not check existing fingerprints; partial sensor profile cleared','System',NULL,'Failed fingerprint enrollment for employee #11, slot 11, mapping v6: Could not check existing fingerprints; partial sensor profile cleared',NULL,NULL,NULL,'2026-08-27 23:03:21'),(233,1,'Queued fingerprint enrollment v7 for employee #11 in slot 11','System',NULL,'Queued fingerprint enrollment v7 for employee #11 in slot 11',NULL,NULL,NULL,'2026-08-27 23:03:34'),(234,1,'Completed fingerprint enrollment for employee #11, slot 11, mapping v7: Five thumb positions enrolled and verified for profile 11','System',NULL,'Completed fingerprint enrollment for employee #11, slot 11, mapping v7: Five thumb positions enrolled and verified for profile 11',NULL,NULL,NULL,'2026-08-27 23:04:22'),(235,1,'Edited employee record - Grey Zafra','System',NULL,'Edited employee record - Grey Zafra',NULL,NULL,NULL,'2026-08-27 23:04:54'),(236,1,'Queued fingerprint enrollment v5 for employee #28 in slot 28','System',NULL,'Queued fingerprint enrollment v5 for employee #28 in slot 28',NULL,NULL,NULL,'2026-08-27 23:05:00'),(237,1,'Completed fingerprint enrollment for employee #28, slot 28, mapping v5: Five thumb positions enrolled and verified for profile 28','System',NULL,'Completed fingerprint enrollment for employee #28, slot 28, mapping v5: Five thumb positions enrolled and verified for profile 28',NULL,NULL,NULL,'2026-08-27 23:06:27'),(238,1,'Queued fingerprint enrollment v6 for employee #28 in slot 28','System',NULL,'Queued fingerprint enrollment v6 for employee #28 in slot 28',NULL,NULL,NULL,'2026-08-27 23:07:05'),(239,1,'Failed fingerprint enrollment for employee #28, slot 28, mapping v6: Could not check existing fingerprints; partial sensor profile cleared','System',NULL,'Failed fingerprint enrollment for employee #28, slot 28, mapping v6: Could not check existing fingerprints; partial sensor profile cleared',NULL,NULL,NULL,'2026-08-27 23:07:55'),(242,1,'Queued fingerprint enrollment v7 for employee #28 in slot 28','System',NULL,'Queued fingerprint enrollment v7 for employee #28 in slot 28',NULL,NULL,NULL,'2026-08-27 23:44:28'),(243,1,'Failed fingerprint enrollment for employee #28, slot 28, mapping v7: Remove finger timeout; partial sensor profile cleared','System',NULL,'Failed fingerprint enrollment for employee #28, slot 28, mapping v7: Remove finger timeout; partial sensor profile cleared',NULL,NULL,NULL,'2026-08-27 23:45:32'),(244,1,'Queued fingerprint enrollment v8 for employee #28 in slot 28','System',NULL,'Queued fingerprint enrollment v8 for employee #28 in slot 28',NULL,NULL,NULL,'2026-08-27 23:46:02'),(245,1,'Completed fingerprint enrollment for employee #28, slot 28, mapping v8: Five thumb positions enrolled and verified for profile 28','System',NULL,'Completed fingerprint enrollment for employee #28, slot 28, mapping v8: Five thumb positions enrolled and verified for profile 28',NULL,NULL,NULL,'2026-08-27 23:47:35'),(246,1,'Edited employee record - Navi Sukihero','System',NULL,'Edited employee record - Navi Sukihero',NULL,NULL,NULL,'2026-08-27 23:52:12'),(247,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-30 07:06:28'),(248,1,'Ran payroll - Aug 15-30, 2026','System',NULL,'Ran payroll - Aug 15-30, 2026',NULL,NULL,NULL,'2026-08-30 07:09:04'),(253,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-30 08:08:05'),(254,1,'Issued employee portal activation link for EMP-0010','System',NULL,'Issued employee portal activation link for EMP-0010',NULL,NULL,NULL,'2026-08-30 08:09:22'),(255,1,'Set temporary employee portal password for EMP-0010','System',NULL,'Set temporary employee portal password for EMP-0010',NULL,NULL,NULL,'2026-08-30 08:10:02'),(256,1,'Edited employee record - Jules Ivan Zafra','System',NULL,'Edited employee record - Jules Ivan Zafra',NULL,NULL,NULL,'2026-08-30 08:14:03'),(257,1,'Set temporary employee portal password for EMP-1002','System',NULL,'Set temporary employee portal password for EMP-1002',NULL,NULL,NULL,'2026-08-30 08:14:23'),(258,1,'Edited employee record - Grey Zafra','System',NULL,'Edited employee record - Grey Zafra',NULL,NULL,NULL,'2026-08-30 08:16:17'),(259,1,'Set temporary employee portal password for EMP-1012','System',NULL,'Set temporary employee portal password for EMP-1012',NULL,NULL,NULL,'2026-08-30 08:16:51'),(260,1,'Edited employee record - Navi Sukihero','System',NULL,'Edited employee record - Navi Sukihero',NULL,NULL,NULL,'2026-08-30 08:17:51'),(261,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-08-30 20:45:18'),(270,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-09-04 13:46:22'),(271,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-09-04 13:49:23'),(272,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-09-04 13:50:23'),(273,1,'Set temporary employee portal password for EMP-0010','System',NULL,'Set temporary employee portal password for EMP-0010',NULL,NULL,NULL,'2026-09-04 13:52:59'),(276,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-09-05 19:52:13'),(279,1,'Logged in','System',NULL,'Logged in',NULL,NULL,NULL,'2026-09-06 05:44:46'),(280,1,'Logged out','System',NULL,'Logged out',NULL,NULL,NULL,'2026-09-06 05:45:22'),(283,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 09:56:27'),(284,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 09:59:07'),(285,1,'Employee and compensation updated','Employees','1','Updated employee EMP-0010','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":null,\"pay_type\":\"Daily\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":650,\"status\":\"Active\"}','::1','2026-09-06 10:25:24'),(286,1,'Employee and compensation updated','Employees','11','Updated employee EMP-1002','{\"first_name\":\"Grey\",\"middle_name\":\"A\",\"last_name\":\"Zafra\",\"department_id\":3,\"position\":\"Finance executive\",\"employment_type\":null,\"pay_type\":\"Daily\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Grey\",\"middle_name\":\"A\",\"last_name\":\"Zafra\",\"department_id\":3,\"position\":\"Finance executive\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":650,\"status\":\"Active\"}','::1','2026-09-06 10:29:08'),(287,1,'Queued fingerprint enrollment v8 for employee #11 in slot 11','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:52:56'),(288,1,'Failed fingerprint enrollment for employee #11, slot 11, mapping v8: Enrollment cancelled at biometric terminal by BTN2','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:53:04'),(289,1,'Queued fingerprint enrollment v9 for employee #11 in slot 11','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:53:09'),(290,1,'Failed fingerprint enrollment for employee #11, slot 11, mapping v9: Sensor needs a fresh thumb placement for this angle','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:53:46'),(291,1,'Queued fingerprint enrollment v10 for employee #11 in slot 11','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:53:55'),(292,1,'Completed fingerprint enrollment for employee #11, slot 11, mapping v10: Five thumb positions enrolled and verified for profile 11','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:55:24'),(293,1,'Employee updated','Employees','11','Updated employee EMP-1002','{\"first_name\":\"Grey\",\"middle_name\":\"A\",\"last_name\":\"Zafra\",\"department_id\":3,\"position\":\"Finance executive\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Grey\",\"middle_name\":\"A\",\"last_name\":\"Zafra\",\"department_id\":3,\"position\":\"Finance executive\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":650,\"status\":\"Active\"}','::1','2026-09-06 10:55:45'),(294,1,'Queued fingerprint enrollment v9 for employee #28 in slot 28','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:55:50'),(295,1,'Failed fingerprint enrollment for employee #28, slot 28, mapping v9: Sensor needs a fresh thumb placement for this angle','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:56:22'),(296,1,'Queued fingerprint enrollment v10 for employee #28 in slot 28','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:56:29'),(297,1,'Failed fingerprint enrollment for employee #28, slot 28, mapping v10: Position did not match the same employee thumb; partial sensor profile cleared','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:57:36'),(298,1,'Queued fingerprint enrollment v11 for employee #28 in slot 28','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:57:41'),(299,1,'Completed fingerprint enrollment for employee #28, slot 28, mapping v11: Five thumb positions enrolled and verified for profile 28','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 10:59:06'),(300,1,'Employee and compensation updated','Employees','28','Updated employee EMP-1012','{\"first_name\":\"Navi\",\"middle_name\":\"B\",\"last_name\":\"Sukihero\",\"department_id\":5,\"position\":\"Maintenance staff\",\"employment_type\":null,\"pay_type\":\"Daily\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Navi\",\"middle_name\":\"B\",\"last_name\":\"Sukihero\",\"department_id\":5,\"position\":\"Maintenance staff\",\"employment_type\":\"Part-Time\",\"pay_type\":\"Hourly\",\"basic_rate\":150,\"status\":\"Active\"}','::1','2026-09-06 11:00:14'),(301,1,'Assigned complete weekly schedule to EMP-1012','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 11:01:51'),(302,1,'Daily attendance processed','Attendance','2026-09-06','Processed attendance date 2026-09-06.',NULL,'{\"processed\":3,\"deferred\":0}','::1','2026-09-06 11:03:16'),(307,1,'Queued fingerprint enrollment v8 for employee #1 in slot 1','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 11:35:15'),(308,1,'Completed fingerprint enrollment for employee #1, slot 1, mapping v8: Five thumb positions enrolled and verified for profile 1','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 11:36:28'),(309,1,'Employee updated','Employees','1','Updated employee EMP-0010','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":650,\"status\":\"Active\"}','127.0.0.1','2026-09-06 11:36:49'),(310,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 16:04:22'),(313,NULL,'Removed migration overtime placeholders','Overtime',NULL,'Removed only the three migration-generated pending overtime placeholder rows.',NULL,'{\"removed_rows\":3,\"payroll_policy\":\"Late deduction plus Overtime and Holiday earnings\"}',NULL,'2026-09-06 16:33:21'),(316,1,'Employee schedule assigned','Work Schedule','28','Assigned a complete flexible weekly schedule and confirmed employment/pay setup for EMP-1012.','{\"employment_type\":\"Part-Time\",\"pay_type\":\"Hourly\",\"basic_rate\":\"150.00\"}','{\"employment_type\":\"Part-Time\",\"pay_type\":\"Hourly\",\"basic_rate\":150,\"work_days\":[\"Monday\",\"Wednesday\",\"Friday\"]}','::1','2026-09-06 17:06:59'),(317,1,'Holiday added','Holidays','1','Added holiday Bonifacio Day.',NULL,'{\"holiday_name\":\"Bonifacio Day\",\"holiday_date\":\"2026-11-30\",\"holiday_type\":\"Regular Holiday\",\"description\":null,\"status\":\"Active\"}','::1','2026-09-06 17:10:36'),(318,1,'Holiday added','Holidays','2','Added holiday Christmas Day.',NULL,'{\"holiday_name\":\"Christmas Day\",\"holiday_date\":\"2026-12-25\",\"holiday_type\":\"Regular Holiday\",\"description\":null,\"status\":\"Active\"}','::1','2026-09-06 17:12:01'),(319,1,'Holiday modified','Holidays','2','Updated holiday Christmas Day.','{\"id\":2,\"holiday_name\":\"Christmas Day\",\"holiday_date\":\"2026-12-25\",\"holiday_type\":\"Regular Holiday\",\"description\":null,\"status\":\"Active\",\"created_by\":1,\"created_at\":\"2026-09-06 17:12:01\",\"updated_at\":\"2026-09-06 17:12:01\"}','{\"holiday_name\":\"Christmas Day\",\"holiday_date\":\"2026-12-25\",\"holiday_type\":\"Regular Holiday\",\"description\":null,\"status\":\"Active\"}','::1','2026-09-06 17:12:08'),(320,1,'Holiday added','Holidays','3','Added holiday Rizal Day.',NULL,'{\"holiday_name\":\"Rizal Day\",\"holiday_date\":\"2026-12-30\",\"holiday_type\":\"Regular Holiday\",\"description\":null,\"status\":\"Active\"}','::1','2026-09-06 17:12:36'),(325,1,'Employee schedule assigned','Work Schedule','28','Assigned a complete flexible weekly schedule and confirmed employment/pay setup for EMP-1012.','{\"employment_type\":\"Part-Time\",\"pay_type\":\"Hourly\",\"basic_rate\":\"150.00\"}','{\"employment_type\":\"Part-Time\",\"pay_type\":\"Hourly\",\"basic_rate\":150,\"work_days\":[\"Monday\",\"Wednesday\",\"Friday\"]}','::1','2026-09-06 18:06:58'),(326,1,'Employee schedule assigned','Work Schedule','11','Assigned a complete flexible weekly schedule and confirmed employment/pay setup for EMP-1002.','{\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":\"650.00\"}','{\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":650,\"work_days\":[\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\",\"Saturday\"]}','::1','2026-09-06 18:11:00'),(327,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 20:42:23'),(328,1,'Payroll settings changed','Payroll Settings',NULL,'Updated centralized attendance and payroll policy values.','{\"break_duration\":\"60\",\"currency\":\"PHP\",\"employee_overtime_requests_enabled\":\"1\",\"grace_minutes\":\"15\",\"late_deduction_enabled\":\"1\",\"overtime_enabled\":\"1\",\"overtime_multiplier\":\"1.25\",\"overtime_requires_approval\":\"1\",\"payroll_frequency\":\"Semi-monthly\",\"regular_holiday_overtime_multiplier\":\"1.00\",\"regular_holiday_rest_day_multiplier\":\"1.00\",\"regular_holiday_worked_multiplier\":\"1.00\",\"regular_hours_per_day\":\"8\",\"rest_day_multiplier\":\"1.00\",\"rounding_rule\":\"nearest_cent\",\"special_day_overtime_multiplier\":\"1.00\",\"special_day_rest_day_multiplier\":\"1.00\",\"special_day_worked_multiplier\":\"1.00\",\"working_day_basis\":\"22\"}','{\"late_deduction_enabled\":\"1\",\"overtime_enabled\":\"1\",\"employee_overtime_requests_enabled\":\"1\",\"regular_hours_per_day\":\"8\",\"working_day_basis\":\"22\",\"grace_minutes\":\"15\",\"break_duration\":\"60\",\"overtime_multiplier\":\"1.25\",\"regular_holiday_worked_multiplier\":\"1\",\"regular_holiday_overtime_multiplier\":\"1\",\"special_day_worked_multiplier\":\"1\",\"special_day_overtime_multiplier\":\"1\",\"rest_day_multiplier\":\"1\",\"regular_holiday_rest_day_multiplier\":\"1\",\"special_day_rest_day_multiplier\":\"1\",\"overtime_requires_approval\":\"1\",\"payroll_frequency\":\"Semi-monthly\",\"rounding_rule\":\"nearest_cent\",\"currency\":\"PHP\"}','::1','2026-09-06 21:03:18'),(331,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-06 22:10:15'),(332,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-07 10:19:56'),(333,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-07 16:31:16'),(334,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-07 17:08:16'),(335,NULL,'Employee submitted leave','Leave','1','Submitted Paid Leave for 2026-09-07 through 2026-09-08. [Employee #1]',NULL,NULL,'::1','2026-09-07 17:13:34'),(336,1,'Leave approved','Leave','1','Approved leave request for EMP-0010.','{\"status\":\"Pending\"}','{\"status\":\"Approved\",\"decision_note\":\"okay\"}','::1','2026-09-07 17:13:55'),(337,1,'Employee added','Employees','154','Registered EMP-1029 and created a pending employee portal account.',NULL,'{\"employee_no\":\"EMP-1029\",\"name\":\"Cristine Kate Cadoldolan\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":450,\"status\":\"Active\"}','::1','2026-09-07 17:50:53'),(338,1,'Queued fingerprint enrollment v1 for employee #154 in slot 15','System',NULL,NULL,NULL,NULL,NULL,'2026-09-07 17:51:05'),(339,1,'Set temporary employee portal password for EMP-1029','System',NULL,NULL,NULL,NULL,NULL,'2026-09-07 17:53:59'),(340,1,'Employee updated','Employees','154','Updated employee EMP-1029','{\"first_name\":\"Cristine Kate\",\"middle_name\":\"\",\"last_name\":\"Cadoldolan\",\"department_id\":3,\"position\":\"Finance officer\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":\"450.00\",\"status\":\"Active\"}','{\"first_name\":\"Cristine Kate\",\"middle_name\":\"\",\"last_name\":\"Cadoldolan\",\"department_id\":3,\"position\":\"Finance officer\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":450,\"status\":\"Active\"}','::1','2026-09-07 17:56:28'),(341,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-07 21:02:22'),(342,1,'Daily attendance processed','Attendance','2026-09-07','Processed attendance date 2026-09-07.',NULL,'{\"processed\":4,\"deferred\":0}','::1','2026-09-07 21:03:39'),(343,1,'Daily attendance processed','Attendance','2026-09-07','Processed attendance date 2026-09-07.',NULL,'{\"processed\":4,\"deferred\":0}','::1','2026-09-07 21:03:58'),(345,1,'Employee schedule assigned','Work Schedule','154','Assigned a complete flexible weekly schedule and confirmed employment/pay setup for EMP-1029.','{\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":\"450.00\"}','{\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":450,\"work_days\":[\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"]}','::1','2026-09-07 21:30:20'),(348,1,'Daily attendance processed','Attendance','2026-09-07','Processed attendance date 2026-09-07.',NULL,'{\"processed\":4,\"deferred\":0}','::1','2026-09-07 22:20:27'),(349,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-09 14:17:42'),(350,1,'Daily attendance processed','Attendance','2026-09-09','Processed attendance date 2026-09-09.',NULL,'{\"processed\":0,\"deferred\":4}','::1','2026-09-09 14:17:56'),(351,1,'Daily attendance processed','Attendance','2026-09-09','Processed attendance date 2026-09-09.',NULL,'{\"processed\":0,\"deferred\":4}','::1','2026-09-09 14:17:59'),(352,1,'Logged out','System',NULL,NULL,NULL,NULL,NULL,'2026-09-09 14:20:14'),(353,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-09 15:01:04'),(354,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-09 16:18:24'),(357,1,'Daily attendance processed','Attendance','2026-09-09','Processed attendance date 2026-09-09.',NULL,'{\"processed\":0,\"deferred\":4}','::1','2026-09-09 16:56:04'),(360,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-09 22:37:17'),(361,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-09 22:37:59'),(363,1,'Logged out','System',NULL,NULL,NULL,NULL,NULL,'2026-09-09 23:09:22'),(364,1,'Employee updated','Employees','1','Updated employee EMP-0010','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":650,\"status\":\"Active\"}','127.0.0.1','2026-09-09 23:14:20'),(365,1,'Employee updated','Employees','11','Updated employee EMP-1002','{\"first_name\":\"Grey\",\"middle_name\":\"A\",\"last_name\":\"Zafra\",\"department_id\":3,\"position\":\"Finance executive\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Grey\",\"middle_name\":\"A\",\"last_name\":\"Zafra\",\"department_id\":3,\"position\":\"Finance executive\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":650,\"status\":\"Active\"}','127.0.0.1','2026-09-09 23:14:35'),(366,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-10 06:34:54'),(367,1,'Daily attendance processed','Attendance','2026-09-10','Processed attendance date 2026-09-10.',NULL,'{\"processed\":1,\"deferred\":3}','127.0.0.1','2026-09-10 06:35:09'),(368,1,'Daily attendance processed','Attendance','2026-09-09','Processed attendance date 2026-09-09.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-10 06:35:16'),(371,1,'Employee and compensation updated','Employees','1','Updated employee EMP-0010','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":650,\"status\":\"Active\"}','127.0.0.1','2026-09-10 07:31:09'),(372,1,'Employee and compensation updated','Employees','11','Updated employee EMP-1002','{\"first_name\":\"Grey\",\"middle_name\":\"A\",\"last_name\":\"Zafra\",\"department_id\":3,\"position\":\"Finance executive\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Monthly\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Grey\",\"middle_name\":\"A\",\"last_name\":\"Zafra\",\"department_id\":3,\"position\":\"Finance executive\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":650,\"status\":\"Active\"}','127.0.0.1','2026-09-10 07:31:29'),(373,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-10 07:34:36'),(374,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-10 14:34:29'),(375,1,'Daily attendance processed','Attendance','2026-09-10','Processed attendance date 2026-09-10.',NULL,'{\"processed\":1,\"deferred\":3}','127.0.0.1','2026-09-10 14:37:41'),(376,1,'Logged out','System',NULL,NULL,NULL,NULL,NULL,'2026-09-10 14:39:07'),(377,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-10 14:43:45'),(378,1,'Failed fingerprint enrollment for employee #154, slot 15, mapping v1: Enrollment cancelled at biometric terminal by BTN2','System',NULL,NULL,NULL,NULL,NULL,'2026-09-10 19:00:03'),(379,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-10 19:15:56'),(380,1,'Daily attendance processed','Attendance','2026-09-10','Processed attendance date 2026-09-10.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-10 19:17:12'),(381,1,'Employee and compensation updated','Employees','28','Updated employee EMP-1012','{\"first_name\":\"Navi\",\"middle_name\":\"B\",\"last_name\":\"Sukihero\",\"department_id\":5,\"position\":\"Maintenance staff\",\"employment_type\":\"Part-Time\",\"pay_type\":\"Daily\",\"basic_rate\":\"1200.00\",\"status\":\"Active\"}','{\"first_name\":\"Navi\",\"middle_name\":\"B\",\"last_name\":\"Sukihero\",\"department_id\":5,\"position\":\"Maintenance staff\",\"employment_type\":\"Part-Time\",\"pay_type\":\"Daily\",\"basic_rate\":300,\"status\":\"Active\"}','127.0.0.1','2026-09-10 20:06:38'),(382,1,'Logged out','System',NULL,NULL,NULL,NULL,NULL,'2026-09-10 20:08:48'),(383,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-11 06:13:54'),(384,1,'Overtime rejected','Overtime','102','Rejected overtime for EMP-0010.','{\"status\":\"Pending\",\"approved_minutes\":0}','{\"status\":\"Rejected\",\"approved_minutes\":0,\"decision_note\":\"no\"}','127.0.0.1','2026-09-11 06:21:05'),(387,1,'Employee updated','Employees','1','Updated employee EMP-0010','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":650,\"status\":\"Active\"}','127.0.0.1','2026-09-11 06:40:10'),(390,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-11 20:55:47'),(391,1,'Daily attendance processed','Attendance','2026-09-11','Processed attendance date 2026-09-11.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-11 20:57:02'),(393,1,'Daily attendance processed','Attendance','2026-09-11','Processed attendance date 2026-09-11.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-11 21:23:41'),(394,1,'Employee schedule assigned','Work Schedule','28','Assigned a complete flexible weekly schedule and confirmed employment/pay setup for EMP-1012.','{\"employment_type\":\"Part-Time\",\"pay_type\":\"Daily\",\"basic_rate\":\"300.00\"}','{\"employment_type\":\"Part-Time\",\"pay_type\":\"Daily\",\"basic_rate\":300,\"work_days\":[\"Monday\",\"Wednesday\",\"Friday\"]}','127.0.0.1','2026-09-11 21:25:54'),(395,1,'Overtime rejected','Overtime','131','Rejected overtime for EMP-0010.','{\"status\":\"Pending\",\"approved_minutes\":0}','{\"status\":\"Rejected\",\"approved_minutes\":0,\"decision_note\":null}','127.0.0.1','2026-09-11 21:31:31'),(396,1,'Overtime rejected','Overtime','133','Rejected overtime for EMP-1012.','{\"status\":\"Pending\",\"approved_minutes\":0}','{\"status\":\"Rejected\",\"approved_minutes\":0,\"decision_note\":null}','127.0.0.1','2026-09-11 21:31:36'),(397,1,'Overtime rejected','Overtime','132','Rejected overtime for EMP-1002.','{\"status\":\"Pending\",\"approved_minutes\":0}','{\"status\":\"Rejected\",\"approved_minutes\":0,\"decision_note\":null}','127.0.0.1','2026-09-11 21:31:40'),(398,1,'Daily attendance processed','Attendance','2026-09-11','Processed attendance date 2026-09-11.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-11 21:31:53'),(399,1,'Daily attendance processed','Attendance','2026-09-11','Processed attendance date 2026-09-11.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-11 21:32:06'),(401,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-11 21:45:24'),(402,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-12 10:24:39'),(404,1,'Daily attendance processed','Attendance','2026-09-12','Processed attendance date 2026-09-12.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-12 10:57:50'),(407,1,'Employee and compensation updated','Employees','28','Updated employee EMP-1012','{\"first_name\":\"Navi\",\"middle_name\":\"B\",\"last_name\":\"Sukihero\",\"department_id\":5,\"position\":\"Maintenance staff\",\"employment_type\":\"Part-Time\",\"pay_type\":\"Daily\",\"basic_rate\":\"300.00\",\"status\":\"Active\"}','{\"first_name\":\"Navi\",\"middle_name\":\"B\",\"last_name\":\"Sukihero\",\"department_id\":5,\"position\":\"Maintenance staff\",\"employment_type\":\"Part-Time\",\"pay_type\":\"Hourly\",\"basic_rate\":150,\"status\":\"Active\"}','127.0.0.1','2026-09-12 11:50:28'),(409,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-12 20:53:36'),(410,1,'Daily attendance processed','Attendance','2026-09-12','Processed attendance date 2026-09-12.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-12 20:57:13'),(411,1,'Overtime rejected','Overtime','177','Rejected overtime for EMP-1002.','{\"status\":\"Pending\",\"approved_minutes\":0}','{\"status\":\"Rejected\",\"approved_minutes\":0,\"decision_note\":null}','127.0.0.1','2026-09-12 21:00:44'),(412,1,'Overtime rejected','Overtime','176','Rejected overtime for EMP-0010.','{\"status\":\"Pending\",\"approved_minutes\":0}','{\"status\":\"Rejected\",\"approved_minutes\":0,\"decision_note\":null}','127.0.0.1','2026-09-12 21:00:50'),(414,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-13 14:35:54'),(415,1,'Daily attendance processed','Attendance','2026-09-13','Processed attendance date 2026-09-13.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-13 14:36:01'),(416,1,'Daily attendance processed','Attendance','2026-09-11','Processed attendance date 2026-09-11.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-13 14:37:51'),(418,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-13 20:29:42'),(419,1,'Daily attendance processed','Attendance','2026-09-13','Processed attendance date 2026-09-13.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-13 20:29:50'),(424,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-14 14:12:12'),(425,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-14 16:20:51'),(426,1,'Daily attendance processed','Attendance','2026-09-14','Processed attendance date 2026-09-14.',NULL,'{\"processed\":3,\"deferred\":1}','127.0.0.1','2026-09-14 16:24:42'),(427,1,'Queued fingerprint enrollment v2 for employee #154 in slot 15','System',NULL,NULL,NULL,NULL,NULL,'2026-09-14 16:25:37'),(428,1,'Failed fingerprint enrollment for employee #154, slot 15, mapping v2: Enrollment cancelled at biometric terminal by BTN2','System',NULL,NULL,NULL,NULL,NULL,'2026-09-14 16:25:50'),(429,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-14 20:13:18'),(433,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-15 08:00:47'),(434,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-15 08:01:06'),(441,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-15 08:24:40'),(442,1,'Daily attendance processed','Attendance','2026-09-15','Processed attendance date 2026-09-15.',NULL,'{\"processed\":1,\"deferred\":3}','127.0.0.1','2026-09-15 08:24:50'),(443,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-15 18:59:12'),(444,1,'Daily attendance processed','Attendance','2026-09-15','Processed attendance date 2026-09-15.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-15 19:31:04'),(445,1,'Daily attendance processed','Attendance','2026-09-06','Processed attendance date 2026-09-06.',NULL,'{\"processed\":3,\"deferred\":0}','127.0.0.1','2026-09-15 19:42:49'),(446,1,'Daily attendance processed','Attendance','2026-09-15','Processed attendance date 2026-09-15.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-15 21:40:46'),(447,1,'Logged out','System',NULL,NULL,NULL,NULL,NULL,'2026-09-15 21:43:01'),(448,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-15 21:44:04'),(449,1,'Logged out','System',NULL,NULL,NULL,NULL,NULL,'2026-09-15 21:45:16'),(450,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-16 14:37:40'),(451,1,'Daily attendance processed','Attendance','2026-09-16','Processed attendance date 2026-09-16.',NULL,'{\"processed\":0,\"deferred\":4}','127.0.0.1','2026-09-16 14:46:10'),(452,1,'Daily attendance processed','Attendance','2026-09-16','Processed attendance date 2026-09-16.',NULL,'{\"processed\":0,\"deferred\":4}','127.0.0.1','2026-09-16 14:46:16'),(453,1,'Daily attendance processed','Attendance','2026-09-16','Processed attendance date 2026-09-16.',NULL,'{\"processed\":0,\"deferred\":4}','127.0.0.1','2026-09-16 14:58:27'),(454,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-16 15:01:00'),(455,1,'Daily attendance processed','Attendance','2026-09-12','Processed attendance date 2026-09-12.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-16 15:01:27'),(456,1,'Payroll settings changed','Payroll Settings',NULL,'Updated centralized attendance and payroll policy values.','{\"break_duration\":\"60\",\"currency\":\"PHP\",\"employee_overtime_requests_enabled\":\"1\",\"full_day_minimum_percent\":\"75\",\"grace_minutes\":\"15\",\"half_day_minimum_percent\":\"50\",\"late_deduction_enabled\":\"1\",\"overtime_enabled\":\"1\",\"overtime_multiplier\":\"1.25\",\"overtime_requires_approval\":\"1\",\"payroll_frequency\":\"Semi-monthly\",\"regular_holiday_overtime_multiplier\":\"1\",\"regular_holiday_rest_day_multiplier\":\"1\",\"regular_holiday_worked_multiplier\":\"1\",\"regular_hours_per_day\":\"8\",\"rest_day_multiplier\":\"1\",\"rounding_rule\":\"nearest_cent\",\"special_day_overtime_multiplier\":\"1\",\"special_day_rest_day_multiplier\":\"1\",\"special_day_worked_multiplier\":\"1\",\"undertime_deduction_enabled\":\"1\"}','{\"late_deduction_enabled\":\"1\",\"undertime_deduction_enabled\":\"1\",\"overtime_enabled\":\"1\",\"employee_overtime_requests_enabled\":\"1\",\"regular_hours_per_day\":\"8\",\"grace_minutes\":\"15\",\"break_duration\":\"60\",\"half_day_minimum_percent\":\"50\",\"full_day_minimum_percent\":\"75\",\"overtime_multiplier\":\"1.25\",\"regular_holiday_worked_multiplier\":\"1\",\"regular_holiday_overtime_multiplier\":\"1\",\"special_day_worked_multiplier\":\"1\",\"special_day_overtime_multiplier\":\"1\",\"rest_day_multiplier\":\"1\",\"regular_holiday_rest_day_multiplier\":\"1\",\"special_day_rest_day_multiplier\":\"1\",\"overtime_requires_approval\":\"1\",\"payroll_frequency\":\"Semi-monthly\",\"rounding_rule\":\"nearest_cent\",\"currency\":\"PHP\"}','127.0.0.1','2026-09-16 15:02:12'),(457,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-16 15:06:00'),(458,1,'Logged out','System',NULL,NULL,NULL,NULL,NULL,'2026-09-16 15:12:55'),(459,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-16 15:13:08'),(460,1,'Daily attendance processed','Attendance','2026-09-16','Processed attendance date 2026-09-16.',NULL,'{\"processed\":0,\"deferred\":4}','127.0.0.1','2026-09-16 15:13:12'),(461,1,'Daily attendance processed','Attendance','2026-09-16','Processed attendance date 2026-09-16.',NULL,'{\"processed\":0,\"deferred\":4}','127.0.0.1','2026-09-16 15:49:58'),(462,1,'Logged out','System',NULL,NULL,NULL,NULL,NULL,'2026-09-16 15:53:17'),(463,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-16 20:21:08'),(464,1,'Daily attendance processed','Attendance','2026-09-16','Processed attendance date 2026-09-16.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-16 20:21:16'),(465,1,'Daily attendance processed','Attendance','2026-09-16','Processed attendance date 2026-09-16.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-16 20:49:32'),(466,1,'Daily attendance processed','Attendance','2026-09-12','Processed attendance date 2026-09-12.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-16 21:21:09'),(467,1,'Daily attendance processed','Attendance','2026-09-11','Processed attendance date 2026-09-11.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-16 21:21:15'),(468,1,'Daily attendance processed','Attendance','2026-08-31','Processed attendance date 2026-08-31.',NULL,'{\"processed\":3,\"deferred\":0}','127.0.0.1','2026-09-16 21:21:25'),(469,1,'Employee updated','Employees','1','Updated employee EMP-0010','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":650,\"status\":\"Active\"}','127.0.0.1','2026-09-16 21:36:07'),(470,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-17 06:42:18'),(471,1,'Daily attendance processed','Attendance','2026-09-17','Processed attendance date 2026-09-17.',NULL,'{\"processed\":1,\"deferred\":3}','127.0.0.1','2026-09-17 06:42:30'),(472,1,'Payroll settings changed','Payroll Settings',NULL,'Updated centralized attendance and payroll policy values.','{\"break_duration\":\"60\",\"currency\":\"PHP\",\"employee_overtime_requests_enabled\":\"1\",\"full_day_minimum_percent\":\"75\",\"grace_minutes\":\"15\",\"half_day_minimum_percent\":\"50\",\"late_deduction_enabled\":\"1\",\"overtime_enabled\":\"1\",\"overtime_multiplier\":\"1.25\",\"overtime_requires_approval\":\"1\",\"payroll_frequency\":\"Semi-monthly\",\"regular_holiday_overtime_multiplier\":\"1\",\"regular_holiday_rest_day_multiplier\":\"1\",\"regular_holiday_worked_multiplier\":\"1\",\"regular_hours_per_day\":\"8\",\"rest_day_multiplier\":\"1\",\"rounding_rule\":\"nearest_cent\",\"special_day_overtime_multiplier\":\"1\",\"special_day_rest_day_multiplier\":\"1\",\"special_day_worked_multiplier\":\"1\",\"undertime_deduction_enabled\":\"1\"}','{\"late_deduction_enabled\":\"1\",\"undertime_deduction_enabled\":\"1\",\"overtime_enabled\":\"1\",\"employee_overtime_requests_enabled\":\"1\",\"regular_hours_per_day\":\"8\",\"grace_minutes\":\"15\",\"break_duration\":\"60\",\"half_day_minimum_percent\":\"50\",\"full_day_minimum_percent\":\"75\",\"overtime_multiplier\":\"1.25\",\"regular_holiday_worked_multiplier\":\"1\",\"regular_holiday_overtime_multiplier\":\"1\",\"special_day_worked_multiplier\":\"1\",\"special_day_overtime_multiplier\":\"1\",\"rest_day_multiplier\":\"1\",\"regular_holiday_rest_day_multiplier\":\"1\",\"special_day_rest_day_multiplier\":\"1\",\"overtime_requires_approval\":\"1\",\"payroll_frequency\":\"Semi-monthly\",\"rounding_rule\":\"nearest_cent\",\"currency\":\"PHP\"}','127.0.0.1','2026-09-17 06:45:52'),(473,1,'Employee updated','Employees','1','Updated employee EMP-0010','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":\"650.00\",\"status\":\"Active\"}','{\"first_name\":\"Jules Ivan\",\"middle_name\":\"\",\"last_name\":\"Zafra\",\"department_id\":1,\"position\":\"Admin Aide\",\"employment_type\":\"Full-Time\",\"pay_type\":\"Daily\",\"basic_rate\":650,\"status\":\"Active\"}','127.0.0.1','2026-09-17 06:46:14'),(474,1,'Daily attendance processed','Attendance','2026-09-01','Processed attendance date 2026-09-01.',NULL,'{\"processed\":3,\"deferred\":0}','127.0.0.1','2026-09-17 06:46:54'),(475,1,'Daily attendance processed','Attendance','2026-09-02','Processed attendance date 2026-09-02.',NULL,'{\"processed\":3,\"deferred\":0}','127.0.0.1','2026-09-17 06:47:05'),(476,1,'Daily attendance processed','Attendance','2026-09-03','Processed attendance date 2026-09-03.',NULL,'{\"processed\":3,\"deferred\":0}','127.0.0.1','2026-09-17 06:47:10'),(477,1,'Daily attendance processed','Attendance','2026-09-04','Processed attendance date 2026-09-04.',NULL,'{\"processed\":3,\"deferred\":0}','127.0.0.1','2026-09-17 06:47:15'),(478,1,'Daily attendance processed','Attendance','2026-09-05','Processed attendance date 2026-09-05.',NULL,'{\"processed\":3,\"deferred\":0}','127.0.0.1','2026-09-17 06:47:20'),(479,1,'Daily attendance processed','Attendance','2026-09-06','Processed attendance date 2026-09-06.',NULL,'{\"processed\":3,\"deferred\":0}','127.0.0.1','2026-09-17 06:47:26'),(480,1,'Daily attendance processed','Attendance','2026-09-07','Processed attendance date 2026-09-07.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-17 06:47:35'),(481,1,'Daily attendance processed','Attendance','2026-09-08','Processed attendance date 2026-09-08.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-17 06:47:41'),(482,1,'Daily attendance processed','Attendance','2026-09-12','Processed attendance date 2026-09-12.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-17 06:47:58'),(483,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-17 10:13:57'),(484,1,'Daily attendance processed','Attendance','2026-09-17','Processed attendance date 2026-09-17.',NULL,'{\"processed\":3,\"deferred\":1}','127.0.0.1','2026-09-17 10:14:14'),(485,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-18 06:45:12'),(486,1,'Daily attendance processed','Attendance','2026-09-17','Processed attendance date 2026-09-17.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-18 06:45:27'),(487,1,'Daily attendance processed','Attendance','2026-09-18','Processed attendance date 2026-09-18.',NULL,'{\"processed\":0,\"deferred\":4}','127.0.0.1','2026-09-18 06:46:49'),(488,1,'Logged in','System',NULL,NULL,NULL,NULL,NULL,'2026-09-19 21:11:20'),(489,1,'Daily attendance processed','Attendance','2026-09-19','Processed attendance date 2026-09-19.',NULL,'{\"processed\":4,\"deferred\":0}','127.0.0.1','2026-09-19 21:11:27');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_auth_throttles`
--

DROP TABLE IF EXISTS `admin_auth_throttles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_auth_throttles` (
  `scope_type` enum('Client','Account') NOT NULL,
  `scope_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `failed_attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`scope_type`,`scope_hash`),
  KEY `idx_admin_auth_throttle_expiry` (`locked_until`),
  KEY `idx_admin_auth_throttle_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_auth_throttles`
--

LOCK TABLES `admin_auth_throttles` WRITE;
/*!40000 ALTER TABLE `admin_auth_throttles` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_auth_throttles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `scan_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `expected_time_in` time DEFAULT NULL,
  `expected_time_out` time DEFAULT NULL,
  `schedule_type` enum('Work','Off','Unscheduled') DEFAULT NULL,
  `schedule_source` varchar(32) DEFAULT NULL,
  `schedule_periods_snapshot` longtext DEFAULT NULL,
  `break_minutes` smallint(5) unsigned NOT NULL DEFAULT 0,
  `employment_type_snapshot` varchar(20) DEFAULT NULL,
  `legacy_single_pair` tinyint(1) NOT NULL DEFAULT 0,
  `worked_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `regular_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `late_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `undertime_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `potential_overtime_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `approved_overtime_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `day_classification` varchar(60) NOT NULL DEFAULT 'Normal Work Day',
  `holiday_id` bigint(20) unsigned DEFAULT NULL,
  `leave_request_id` bigint(20) unsigned DEFAULT NULL,
  `overtime_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('Present','Late','Absent','On leave','PRESENT','LATE','UNDERTIME','LATE_AND_UNDERTIME','HALF_DAY','ABSENT','PAID_LEAVE','UNPAID_LEAVE','REST_DAY','REGULAR_HOLIDAY','SPECIAL_NON_WORKING_DAY','HOLIDAY_WORK','REST_DAY_WORK','UNSCHEDULED','INCOMPLETE') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'PRESENT',
  `source` varchar(40) NOT NULL DEFAULT 'Biometric',
  `notes` varchar(255) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `processing_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_scan_date` (`employee_id`,`scan_date`),
  KEY `idx_attendance_date` (`scan_date`),
  KEY `idx_attendance_status_date` (`status`,`scan_date`),
  KEY `idx_attendance_holiday` (`holiday_id`),
  KEY `idx_attendance_leave` (`leave_request_id`),
  CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1681 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (10,1,'2026-08-22','07:13:05','21:40:43','08:00:00','17:00:00','Work','Backfill Default',NULL,0,'Full-Time',1,867,587,0,0,280,0,'Normal Work Day',NULL,NULL,280,'Present','ESP32 Fingerprint','Device: ucc-esp32-01; Command: 1021c149e1ccdfe9d991e0aede3cb784ee1304518f33ce4691ef3de77b8aceda','2026-08-22 07:13:05',1,'2026-09-13 20:19:08','2026-08-22 07:13:05'),(21,11,'2026-08-22','21:39:31','21:45:34','08:00:00','17:00:00','Work','Backfill Default',NULL,0,'Full-Time',1,6,0,819,0,6,0,'Normal Work Day',NULL,NULL,6,'Late','ESP32 Fingerprint','Device: ucc-esp32-01','2026-08-22 21:39:31',1,'2026-09-13 20:19:08','2026-08-22 21:39:31'),(86,1,'2026-08-23','09:59:52','19:15:51',NULL,NULL,'Off','Default',NULL,0,'Full-Time',1,555,555,0,0,0,0,'Rest Day',NULL,NULL,0,'Present','ESP32 Fingerprint','Device: ucc-esp32-01','2026-08-23 09:59:52',1,'2026-09-13 20:19:08','2026-08-23 09:59:52'),(87,11,'2026-08-23','10:00:01','19:17:05',NULL,NULL,'Off','Default',NULL,0,'Full-Time',1,557,557,0,0,0,0,'Rest Day',NULL,NULL,0,'Present','ESP32 Fingerprint','Device: ucc-esp32-01','2026-08-23 10:00:01',1,'2026-09-13 20:19:08','2026-08-23 10:00:01'),(136,28,'2026-08-23','21:14:25','21:15:33',NULL,NULL,'Off','Default',NULL,0,'Part-Time',1,1,1,0,0,0,0,'Rest Day',NULL,NULL,0,'Present','ESP32 Fingerprint','Device: ucc-esp32-01','2026-08-23 21:14:25',1,'2026-09-13 20:19:08','2026-08-23 21:14:25'),(185,1,'2026-08-26','22:30:54','22:36:45','08:00:00','17:00:00','Work','Employee',NULL,0,'Full-Time',1,5,0,855,0,5,0,'Normal Work Day',NULL,NULL,5,'Late','ESP32 Fingerprint','Device: ucc-esp32-01','2026-08-26 22:30:54',1,'2026-09-13 20:19:08','2026-08-26 22:30:54'),(186,11,'2026-08-26','22:37:29',NULL,'08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',0,0,0,465,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Legacy attendance backfill','Device: legacy-import','2026-09-11 21:40:50',4,'2026-09-13 14:16:50','2026-08-26 22:37:29'),(203,1,'2026-08-27','22:57:33',NULL,'08:00:00','17:00:00','Work','Employee',NULL,0,'Full-Time',0,0,0,882,0,0,0,'Normal Work Day',NULL,NULL,0,'Late','ESP32 Fingerprint','Device: ucc-esp32-01','2026-08-27 22:57:33',1,'2026-09-13 14:16:50','2026-08-27 22:57:33'),(204,11,'2026-08-27','23:04:42',NULL,'08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',0,0,0,465,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Legacy attendance backfill','Device: legacy-import','2026-09-11 21:40:50',4,'2026-09-13 14:16:50','2026-08-27 23:04:42'),(213,28,'2026-08-27','23:47:51',NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'INCOMPLETE','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-11 21:25:54',3,'2026-09-13 14:16:50','2026-08-27 23:47:51'),(214,1,'2026-08-30','07:07:02',NULL,NULL,NULL,'Off','Employee',NULL,0,'Full-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'Present','ESP32 Fingerprint','Device: ucc-esp32-01','2026-08-30 07:07:02',1,'2026-09-13 14:16:50','2026-08-30 07:07:02'),(215,11,'2026-08-30','07:07:30',NULL,NULL,NULL,'Off','Employee',NULL,0,'Full-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'INCOMPLETE','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-06 18:11:00',3,'2026-09-13 14:16:50','2026-08-30 07:07:30'),(216,28,'2026-08-30','07:07:39',NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'INCOMPLETE','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-11 21:25:54',3,'2026-09-13 14:16:50','2026-08-30 07:07:39'),(291,1,'2026-09-06','10:52:05',NULL,NULL,NULL,'Off','Employee',NULL,0,'Full-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-17 06:47:26',5,'2026-09-17 06:47:26','2026-09-06 10:52:05'),(292,11,'2026-09-06','10:55:37',NULL,NULL,NULL,'Off','Employee',NULL,0,'Full-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-17 06:47:26',5,'2026-09-17 06:47:26','2026-09-06 10:55:37'),(293,28,'2026-09-06','11:02:36',NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-17 06:47:26',5,'2026-09-17 06:47:26','2026-09-06 11:02:36'),(455,1,'2026-09-07',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Paid Leave',NULL,1,0,'PAID_LEAVE','Attendance Processor',NULL,'2026-09-17 06:47:35',5,'2026-09-17 06:47:35','2026-09-07 17:13:55'),(457,11,'2026-09-07',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:35',5,'2026-09-17 06:47:35','2026-09-07 21:03:39'),(458,28,'2026-09-07',NULL,NULL,'09:00:00','14:00:00','Work','Employee','[{\"period_start\":\"09:00:00\",\"period_end\":\"14:00:00\"}]',0,'Part-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:35',5,'2026-09-17 06:47:35','2026-09-07 21:03:39'),(459,154,'2026-09-07',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:35',5,'2026-09-17 06:47:35','2026-09-07 21:03:39'),(572,28,'2026-09-10',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-11 21:25:54',3,'2026-09-13 14:16:50','2026-09-10 06:35:09'),(573,1,'2026-09-09',NULL,NULL,'08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-10 06:35:16',4,'2026-09-13 14:16:50','2026-09-10 06:35:16'),(574,11,'2026-09-09',NULL,NULL,'08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-10 06:35:16',4,'2026-09-13 14:16:50','2026-09-10 06:35:16'),(575,28,'2026-09-09',NULL,NULL,'09:00:00','17:00:00','Work','Employee','[{\"period_start\":\"09:00:00\",\"period_end\":\"17:00:00\"}]',0,'Part-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-11 21:25:54',3,'2026-09-13 14:16:50','2026-09-10 06:35:16'),(576,154,'2026-09-09',NULL,NULL,'08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-10 06:35:16',4,'2026-09-13 14:16:50','2026-09-10 06:35:16'),(604,1,'2026-09-10','19:15:23','19:16:49','08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',1,1,0,660,0,1,0,'Normal Work Day',NULL,NULL,1,'ABSENT','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-11 06:21:05',4,'2026-09-13 20:19:08','2026-09-10 19:15:23'),(607,11,'2026-09-10','19:22:06','19:22:51','08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',1,0,0,667,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-10 19:22:51',4,'2026-09-13 20:19:08','2026-09-10 19:17:12'),(609,154,'2026-09-10',NULL,NULL,'08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-10 19:17:12',4,'2026-09-13 14:16:50','2026-09-10 19:17:12'),(701,1,'2026-09-11','07:13:59','20:57:37','08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',1,763,480,0,0,237,0,'Normal Work Day',NULL,NULL,237,'PRESENT','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-16 21:21:15',5,'2026-09-16 21:21:15','2026-09-11 07:13:59'),(702,11,'2026-09-11','07:14:46','20:57:50','08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',1,763,480,0,0,237,0,'Normal Work Day',NULL,NULL,237,'PRESENT','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-16 21:21:15',5,'2026-09-16 21:21:15','2026-09-11 07:14:46'),(703,28,'2026-09-11','07:16:39','20:58:02','09:00:00','15:00:00','Work','Employee','[{\"period_start\":\"09:00:00\",\"period_end\":\"11:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"14:00:00\"},{\"period_start\":\"14:00:00\",\"period_end\":\"15:00:00\"}]',120,'Part-Time',1,701,240,0,0,358,0,'Normal Work Day',NULL,NULL,358,'PRESENT','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-16 21:21:15',5,'2026-09-16 21:21:15','2026-09-11 07:16:39'),(707,154,'2026-09-11',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-16 21:21:15',5,'2026-09-16 21:21:15','2026-09-11 20:57:02'),(808,1,'2026-09-12','10:23:17','20:59:37','08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',1,576,336,128,0,239,0,'Normal Work Day',NULL,NULL,239,'HALF_DAY','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-17 06:47:58',5,'2026-09-17 06:47:58','2026-09-12 10:23:17'),(809,11,'2026-09-12','10:23:32','20:59:51','08:00:00','17:00:00','Work','Employee',NULL,60,'Full-Time',1,576,336,128,0,239,0,'Normal Work Day',NULL,NULL,239,'HALF_DAY','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-17 06:47:58',5,'2026-09-17 06:47:58','2026-09-12 10:23:32'),(810,28,'2026-09-12','10:23:46','21:00:03',NULL,NULL,'Off','Employee',NULL,0,'Part-Time',1,636,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY_WORK','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-17 06:47:58',5,'2026-09-17 06:47:58','2026-09-12 10:23:46'),(830,154,'2026-09-12',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Full-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-17 06:47:58',5,'2026-09-17 06:47:58','2026-09-12 10:57:50'),(929,1,'2026-09-13',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Full-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-13 20:29:50',5,'2026-09-13 20:29:50','2026-09-13 14:36:01'),(930,11,'2026-09-13',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Full-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-13 20:29:50',5,'2026-09-13 20:29:50','2026-09-13 14:36:01'),(931,28,'2026-09-13',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-13 20:29:50',5,'2026-09-13 20:29:50','2026-09-13 14:36:01'),(932,154,'2026-09-13',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Full-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-13 20:29:50',5,'2026-09-13 20:29:50','2026-09-13 14:36:01'),(1078,1,'2026-09-14','14:12:37',NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,57,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-14 16:24:42',5,'2026-09-14 16:24:42','2026-09-14 14:12:38'),(1079,11,'2026-09-14','14:13:16',NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,58,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-14 16:24:42',5,'2026-09-14 16:24:42','2026-09-14 14:13:16'),(1080,28,'2026-09-14',NULL,NULL,'09:00:00','14:00:00','Work','Employee','[{\"period_start\":\"09:00:00\",\"period_end\":\"14:00:00\"}]',0,'Part-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-14 16:24:42',5,'2026-09-14 16:24:42','2026-09-14 14:13:34'),(1176,28,'2026-09-15','19:27:15','19:29:36',NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,2,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY_WORK','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-15 21:40:46',5,'2026-09-15 21:40:46','2026-09-15 08:24:50'),(1177,11,'2026-09-15',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-15 21:40:46',5,'2026-09-15 21:40:46','2026-09-15 19:26:08'),(1178,1,'2026-09-15',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-15 21:40:46',5,'2026-09-15 21:40:46','2026-09-15 19:27:03'),(1184,154,'2026-09-15',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-15 21:40:46',5,'2026-09-15 21:40:46','2026-09-15 19:31:04'),(1279,1,'2026-09-16',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-16 20:49:32',5,'2026-09-16 20:49:32','2026-09-16 20:21:16'),(1280,11,'2026-09-16',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-16 20:49:32',5,'2026-09-16 20:49:32','2026-09-16 20:21:16'),(1281,28,'2026-09-16',NULL,NULL,'09:00:00','17:00:00','Work','Employee','[{\"period_start\":\"09:00:00\",\"period_end\":\"17:00:00\"}]',0,'Part-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-16 20:49:32',5,'2026-09-16 20:49:32','2026-09-16 20:21:16'),(1282,154,'2026-09-16',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-16 20:49:32',5,'2026-09-16 20:49:32','2026-09-16 20:21:16'),(1325,1,'2026-08-31',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-16 21:21:25',5,'2026-09-16 21:21:25','2026-09-16 21:21:25'),(1326,11,'2026-08-31',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-16 21:21:25',5,'2026-09-16 21:21:25','2026-09-16 21:21:25'),(1327,28,'2026-08-31',NULL,NULL,'09:00:00','14:00:00','Work','Employee','[{\"period_start\":\"09:00:00\",\"period_end\":\"14:00:00\"}]',0,'Part-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-16 21:21:25',5,'2026-09-16 21:21:25','2026-09-16 21:21:25'),(1358,28,'2026-09-17',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-18 06:45:27',5,'2026-09-18 06:45:27','2026-09-17 06:42:30'),(1359,1,'2026-09-17','06:43:13',NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-18 06:45:27',5,'2026-09-18 06:45:27','2026-09-17 06:43:14'),(1360,11,'2026-09-17','06:44:10',NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','ESP32 Fingerprint','Device: ucc-esp32-01','2026-09-18 06:45:27',5,'2026-09-18 06:45:27','2026-09-17 06:44:10'),(1391,1,'2026-09-01',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:46:54',5,'2026-09-17 06:46:54','2026-09-17 06:46:54'),(1392,11,'2026-09-01',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:46:54',5,'2026-09-17 06:46:54','2026-09-17 06:46:54'),(1393,28,'2026-09-01',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-17 06:46:54',5,'2026-09-17 06:46:54','2026-09-17 06:46:54'),(1394,1,'2026-09-02',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:05',5,'2026-09-17 06:47:05','2026-09-17 06:47:05'),(1395,11,'2026-09-02',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:05',5,'2026-09-17 06:47:05','2026-09-17 06:47:05'),(1396,28,'2026-09-02',NULL,NULL,'09:00:00','17:00:00','Work','Employee','[{\"period_start\":\"09:00:00\",\"period_end\":\"17:00:00\"}]',0,'Part-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:05',5,'2026-09-17 06:47:05','2026-09-17 06:47:05'),(1397,1,'2026-09-03',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:10',5,'2026-09-17 06:47:10','2026-09-17 06:47:10'),(1398,11,'2026-09-03',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:10',5,'2026-09-17 06:47:10','2026-09-17 06:47:10'),(1399,28,'2026-09-03',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-17 06:47:10',5,'2026-09-17 06:47:10','2026-09-17 06:47:10'),(1400,1,'2026-09-04',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:15',5,'2026-09-17 06:47:15','2026-09-17 06:47:15'),(1401,11,'2026-09-04',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:15',5,'2026-09-17 06:47:15','2026-09-17 06:47:15'),(1402,28,'2026-09-04',NULL,NULL,'09:00:00','14:00:00','Work','Employee','[{\"period_start\":\"09:00:00\",\"period_end\":\"14:00:00\"}]',0,'Part-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:15',5,'2026-09-17 06:47:15','2026-09-17 06:47:15'),(1403,1,'2026-09-05',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:20',5,'2026-09-17 06:47:20','2026-09-17 06:47:20'),(1404,11,'2026-09-05',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:20',5,'2026-09-17 06:47:20','2026-09-17 06:47:20'),(1405,28,'2026-09-05',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-17 06:47:20',5,'2026-09-17 06:47:20','2026-09-17 06:47:20'),(1413,1,'2026-09-08',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Paid Leave',NULL,1,0,'PAID_LEAVE','Attendance Processor',NULL,'2026-09-17 06:47:41',5,'2026-09-17 06:47:41','2026-09-17 06:47:41'),(1414,11,'2026-09-08',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:41',5,'2026-09-17 06:47:41','2026-09-17 06:47:41'),(1415,28,'2026-09-08',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-17 06:47:41',5,'2026-09-17 06:47:41','2026-09-17 06:47:41'),(1416,154,'2026-09-08',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-17 06:47:41',5,'2026-09-17 06:47:41','2026-09-17 06:47:41'),(1474,154,'2026-09-17',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-18 06:45:27',5,'2026-09-18 06:45:27','2026-09-18 06:45:27'),(1490,1,'2026-09-19',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-19 21:11:27',5,'2026-09-19 21:11:27','2026-09-19 21:11:27'),(1491,11,'2026-09-19',NULL,NULL,'08:00:00','17:00:00','Work','Employee','[{\"period_start\":\"08:00:00\",\"period_end\":\"12:00:00\"},{\"period_start\":\"13:00:00\",\"period_end\":\"17:00:00\"}]',60,'Full-Time',0,0,0,0,0,0,0,'Normal Work Day',NULL,NULL,0,'ABSENT','Attendance Processor',NULL,'2026-09-19 21:11:27',5,'2026-09-19 21:11:27','2026-09-19 21:11:27'),(1492,28,'2026-09-19',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Part-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-19 21:11:27',5,'2026-09-19 21:11:27','2026-09-19 21:11:27'),(1493,154,'2026-09-19',NULL,NULL,NULL,NULL,'Off','Employee',NULL,0,'Full-Time',0,0,0,0,0,0,0,'Rest Day',NULL,NULL,0,'REST_DAY','Attendance Processor',NULL,'2026-09-19 21:11:27',5,'2026-09-19 21:11:27','2026-09-19 21:11:27');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_logs`
--

DROP TABLE IF EXISTS `attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `fingerprint_id` smallint(5) unsigned DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `action` enum('TIME_IN','TIME_OUT') NOT NULL,
  `session_index` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `punch_sequence` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `scanned_at` datetime(6) NOT NULL,
  `device_id` varchar(80) NOT NULL,
  `command_uuid` varchar(64) DEFAULT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'ESP32 Fingerprint',
  `processed_attendance_id` bigint(20) unsigned DEFAULT NULL,
  `punch_request_id` varchar(96) DEFAULT NULL,
  `event_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_log_event` (`event_key`),
  UNIQUE KEY `uq_attendance_log_session_action` (`employee_id`,`attendance_date`,`session_index`,`action`),
  UNIQUE KEY `uq_attendance_log_device_request` (`device_id`,`punch_request_id`),
  KEY `idx_attendance_log_scanned` (`scanned_at`),
  KEY `idx_attendance_log_fingerprint` (`fingerprint_id`),
  KEY `idx_attendance_log_command` (`command_uuid`),
  KEY `idx_attendance_log_processed` (`processed_attendance_id`),
  KEY `idx_attendance_log_sequence` (`employee_id`,`attendance_date`,`punch_sequence`),
  CONSTRAINT `fk_attendance_log_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_attendance_log_processed` FOREIGN KEY (`processed_attendance_id`) REFERENCES `attendance` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=775 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_logs`
--

LOCK TABLES `attendance_logs` WRITE;
/*!40000 ALTER TABLE `attendance_logs` DISABLE KEYS */;
INSERT INTO `attendance_logs` VALUES (1,1,NULL,'2026-08-22','TIME_IN',1,1,'2026-08-22 07:13:05.000000','legacy-import',NULL,'Legacy attendance backfill',10,NULL,'cbb63bd40b797427884ca665e660cc0b80be1da087006597e1558905e48a866e','2026-09-06 05:45:41'),(2,11,NULL,'2026-08-22','TIME_IN',1,1,'2026-08-22 21:39:31.000000','legacy-import',NULL,'Legacy attendance backfill',21,NULL,'95ea510eed60cce086338e9acf328eeddef5e5230bcb5967d74399b3343aaa77','2026-09-06 05:45:41'),(3,1,NULL,'2026-08-23','TIME_IN',1,1,'2026-08-23 09:59:52.000000','legacy-import',NULL,'Legacy attendance backfill',86,NULL,'febf2adbb58e7b08f38c011ab0535c08e5ae43896cc82124d3f0025bf7304d88','2026-09-06 05:45:41'),(4,11,NULL,'2026-08-23','TIME_IN',1,1,'2026-08-23 10:00:01.000000','legacy-import',NULL,'Legacy attendance backfill',87,NULL,'98059b81c44d8b52f8d0d733461ec3bd18fa0f533a10c9f69454618ffb205760','2026-09-06 05:45:41'),(5,28,NULL,'2026-08-23','TIME_IN',1,1,'2026-08-23 21:14:25.000000','legacy-import',NULL,'Legacy attendance backfill',136,NULL,'de02ae0e8f0b98cd370f7d2ffca972de444c3b1512d8b360de98b7de627471d2','2026-09-06 05:45:41'),(6,1,NULL,'2026-08-26','TIME_IN',1,1,'2026-08-26 22:30:54.000000','legacy-import',NULL,'Legacy attendance backfill',185,NULL,'2042407c878ddabcab2ed233d3aaa982045f2cb63a6a78c8d1cd6830050c209b','2026-09-06 05:45:41'),(7,11,NULL,'2026-08-26','TIME_IN',1,1,'2026-08-26 22:37:29.000000','legacy-import',NULL,'Legacy attendance backfill',186,NULL,'57d394b51307ca5ee9394a40c43ae7dace88febdebd2f662c5ae830b95d4a249','2026-09-06 05:45:41'),(8,1,NULL,'2026-08-27','TIME_IN',1,1,'2026-08-27 22:57:33.000000','legacy-import',NULL,'Legacy attendance backfill',203,NULL,'78b098c429bb751440377fe9941e075f546447b5b05ba24b3ecd1e6c7f1c7bcb','2026-09-06 05:45:41'),(9,11,NULL,'2026-08-27','TIME_IN',1,1,'2026-08-27 23:04:42.000000','legacy-import',NULL,'Legacy attendance backfill',204,NULL,'5e9a621ce5fe545a734fb7bca94850f6224b149361c136490cc2c2eaf247a72a','2026-09-06 05:45:41'),(10,28,NULL,'2026-08-27','TIME_IN',1,1,'2026-08-27 23:47:51.000000','legacy-import',NULL,'Legacy attendance backfill',213,NULL,'3637cadf98db27ddf3f15888702c22b097cd36db997e93dbd5318bd14ccba015','2026-09-06 05:45:41'),(11,1,NULL,'2026-08-30','TIME_IN',1,1,'2026-08-30 07:07:02.000000','legacy-import',NULL,'Legacy attendance backfill',214,NULL,'b432249c3f5a972dd5e9922e6bb03a47e66a99a9ce9965d9d00997ebe11acaf2','2026-09-06 05:45:41'),(12,11,NULL,'2026-08-30','TIME_IN',1,1,'2026-08-30 07:07:30.000000','legacy-import',NULL,'Legacy attendance backfill',215,NULL,'3ac2d8e1cfd94996d39e593fe76ab88a5c97231b1f3a093bac3bb6ab70237ddf','2026-09-06 05:45:41'),(13,28,NULL,'2026-08-30','TIME_IN',1,1,'2026-08-30 07:07:39.000000','legacy-import',NULL,'Legacy attendance backfill',216,NULL,'65da020f0fbfc4d53c8f9443cd5ea7cf29d2e1919aa3e13c6d05f693d915d5e5','2026-09-06 05:45:41'),(16,1,NULL,'2026-08-22','TIME_OUT',1,2,'2026-08-22 21:40:43.000000','legacy-import',NULL,'Legacy attendance backfill',10,NULL,'ecea659c77e6dd87d27fac49ca0214cf2f4da7f9b103b9ae81ead43a77fc55e7','2026-09-06 05:45:41'),(17,11,NULL,'2026-08-22','TIME_OUT',1,2,'2026-08-22 21:45:34.000000','legacy-import',NULL,'Legacy attendance backfill',21,NULL,'9c7627ac5cc51a7dcf5f86b338dd05fc90a5c4395bf4a1884195d59a03f78518','2026-09-06 05:45:41'),(18,1,NULL,'2026-08-23','TIME_OUT',1,2,'2026-08-23 19:15:51.000000','legacy-import',NULL,'Legacy attendance backfill',86,NULL,'a346df40e6c7ba8316890943dcde1a3532a877b8b340f8a174dd1a815feced68','2026-09-06 05:45:41'),(19,11,NULL,'2026-08-23','TIME_OUT',1,2,'2026-08-23 19:17:05.000000','legacy-import',NULL,'Legacy attendance backfill',87,NULL,'5ea0aa4410111bb9d8d5c89bfbc291f685b047a3535848f5108f53877348b708','2026-09-06 05:45:41'),(20,28,NULL,'2026-08-23','TIME_OUT',1,2,'2026-08-23 21:15:33.000000','legacy-import',NULL,'Legacy attendance backfill',136,NULL,'c672215a98ae0970a969e9f4b8f944b291ffd070cf6ed9fdcae92469bddf4dc1','2026-09-06 05:45:41'),(21,1,NULL,'2026-08-26','TIME_OUT',1,2,'2026-08-26 22:36:45.000000','legacy-import',NULL,'Legacy attendance backfill',185,NULL,'df16702f4fbba2bd5cf8ba3f1a735a04a96047e3b4dfa7b81f7ad5b82738401f','2026-09-06 05:45:41'),(26,1,1,'2026-09-06','TIME_IN',1,1,'2026-09-06 10:52:05.025823','ucc-esp32-01',NULL,'ESP32 Fingerprint',291,NULL,'3f78f3dc72d6b82f559c7a23bbabdae40cfcb4833eff4f405ad96a588293541d','2026-09-06 10:52:05'),(27,11,11,'2026-09-06','TIME_IN',1,1,'2026-09-06 10:55:37.637660','ucc-esp32-01',NULL,'ESP32 Fingerprint',292,NULL,'e679476679a04e11c0a527385d5765dd6344079f24163e1d595dc84f2c57e37c','2026-09-06 10:55:37'),(28,28,28,'2026-09-06','TIME_IN',1,1,'2026-09-06 11:02:36.927236','ucc-esp32-01',NULL,'ESP32 Fingerprint',293,NULL,'1fc598d23fe493a6fd88b5973026dd7ac9500a2efaeb4eaeeb4aa4b840e444f7','2026-09-06 11:02:36'),(273,1,1,'2026-09-10','TIME_IN',1,1,'2026-09-10 19:15:23.681158','ucc-esp32-01',NULL,'ESP32 Fingerprint',604,NULL,'2e7698b7dadf352740c5586de68576d66495c2a46e7f62a7cf9f9b2589112909','2026-09-10 19:15:23'),(274,1,1,'2026-09-10','TIME_OUT',1,2,'2026-09-10 19:16:49.991817','ucc-esp32-01',NULL,'ESP32 Fingerprint',604,NULL,'72d267766ebdb9b9d0741622ef0bd41c353580797712210f94bbdd13299e7aa8','2026-09-10 19:16:49'),(275,11,11,'2026-09-10','TIME_IN',1,1,'2026-09-10 19:22:06.015570','ucc-esp32-01',NULL,'ESP32 Fingerprint',607,NULL,'bb2cd75daa9223474b29cdceba30a79f906390c5083099edcf84b5265efaba5a','2026-09-10 19:22:06'),(276,11,11,'2026-09-10','TIME_OUT',1,2,'2026-09-10 19:22:51.163598','ucc-esp32-01',NULL,'ESP32 Fingerprint',607,NULL,'8202a5e0016d2ee5f6cded0c0dae578b348a48adb35f24bd2093d8e10a086439','2026-09-10 19:22:51'),(343,1,1,'2026-09-11','TIME_IN',1,1,'2026-09-11 07:13:59.026472','ucc-esp32-01',NULL,'ESP32 Fingerprint',701,NULL,'80085220058bbe0558e4c316080d08a88b52eb68e7cc3f2ea5ddf54b67f32ea9','2026-09-11 07:13:59'),(344,11,11,'2026-09-11','TIME_IN',1,1,'2026-09-11 07:14:46.776429','ucc-esp32-01',NULL,'ESP32 Fingerprint',702,NULL,'e6d778ade416ec508580cdee19c9a2156af5e5b1fa1eb7a222f0e36b1044e15c','2026-09-11 07:14:46'),(345,28,28,'2026-09-11','TIME_IN',1,1,'2026-09-11 07:16:39.345127','ucc-esp32-01',NULL,'ESP32 Fingerprint',703,NULL,'6dd9ad4517b5f0abb15ca09c5ce8eeb9d6e1b5943c9a7755e67830400b247df3','2026-09-11 07:16:39'),(346,1,1,'2026-09-11','TIME_OUT',1,2,'2026-09-11 20:57:37.336823','ucc-esp32-01',NULL,'ESP32 Fingerprint',701,NULL,'1cc9a4747f01498bf7acb9a1dee235df885060c31e456b613b11dcc81338c081','2026-09-11 20:57:37'),(347,11,11,'2026-09-11','TIME_OUT',1,2,'2026-09-11 20:57:50.847950','ucc-esp32-01',NULL,'ESP32 Fingerprint',702,NULL,'fc411ca876e4a981e06d047c3209dbc8c8aa272a9668b4e8afc87a573feb2b37','2026-09-11 20:57:50'),(348,28,28,'2026-09-11','TIME_OUT',1,2,'2026-09-11 20:58:02.090918','ucc-esp32-01',NULL,'ESP32 Fingerprint',703,NULL,'a3c99ab0861735ecf1468b9f9ddd1b7efd406fa3516a24939a3237456ff5bdbc','2026-09-11 20:58:02'),(419,1,1,'2026-09-12','TIME_IN',1,1,'2026-09-12 10:23:17.356485','ucc-esp32-01',NULL,'ESP32 Fingerprint',808,NULL,'e891f40590a99c85c7e9fcbc695b3080b44194982db6ffcdff16bec6ea91389f','2026-09-12 10:23:17'),(420,11,11,'2026-09-12','TIME_IN',1,1,'2026-09-12 10:23:32.928776','ucc-esp32-01',NULL,'ESP32 Fingerprint',809,NULL,'e29fd8319a5fa7761f7dda8bc9d1f0038ed9bd04ef4b33d44502e07a9f1f7870','2026-09-12 10:23:32'),(421,28,28,'2026-09-12','TIME_IN',1,1,'2026-09-12 10:23:46.742177','ucc-esp32-01',NULL,'ESP32 Fingerprint',810,NULL,'7dcd81d490c579d511edc4473890446ad80390cd24eed277ffc8ee3a1d9403f0','2026-09-12 10:23:46'),(478,1,1,'2026-09-12','TIME_OUT',1,2,'2026-09-12 20:59:37.886314','ucc-esp32-01',NULL,'ESP32 Fingerprint',808,NULL,'350f615f58c514998cbc58c0d686391dcda770e6017588921de4cb57e4e4a90d','2026-09-12 20:59:37'),(479,11,11,'2026-09-12','TIME_OUT',1,2,'2026-09-12 20:59:51.968630','ucc-esp32-01',NULL,'ESP32 Fingerprint',809,NULL,'5cb71afb0ca9e402e22fa8106746d44519378400f7209b038fb1b9cefc134f98','2026-09-12 20:59:51'),(480,28,28,'2026-09-12','TIME_OUT',1,2,'2026-09-12 21:00:03.245515','ucc-esp32-01',NULL,'ESP32 Fingerprint',810,NULL,'ab42cb52f30f5c8c7d402299e9663e199b1ad573eb53391b4e62259b1616f53d','2026-09-12 21:00:03'),(637,1,1,'2026-09-14','TIME_IN',2,3,'2026-09-14 14:12:37.000000','ucc-esp32-01',NULL,'ESP32 Fingerprint',1078,'eb81c6f852953b6f226facba9e0535c8','2dbe0c0d2b0937cdab459117cc772d782917ace3e032241b4afe95d05b7b5b8e','2026-09-14 14:12:38'),(638,11,11,'2026-09-14','TIME_IN',2,3,'2026-09-14 14:13:16.000000','ucc-esp32-01',NULL,'ESP32 Fingerprint',1079,'52e21ca4786b992be06d3c2c9b4af8c3','d846bab52d4b3e28815c3f939bce83702d06928713507fa9b7ef5a6bb066d940','2026-09-14 14:13:16'),(727,28,28,'2026-09-15','TIME_IN',1,1,'2026-09-15 19:27:15.000000','ucc-esp32-01',NULL,'ESP32 Fingerprint',1176,'81038cfe5c4fa8c5e8a2be05274b04f2','e9237356cd8e386ea579b999937752dca663373c69360af57458d089cd52f30f','2026-09-15 19:27:15'),(728,28,28,'2026-09-15','TIME_OUT',1,2,'2026-09-15 19:29:36.000000','ucc-esp32-01',NULL,'ESP32 Fingerprint',1176,'fa680dd37ec675ea02861df5316fff90','feee8565a5df28d3b36f36c8eef632a9eaaaa641521d994fdeab42d260db2a9b','2026-09-15 19:29:35'),(729,1,1,'2026-09-17','TIME_IN',1,1,'2026-09-17 06:43:13.000000','ucc-esp32-01',NULL,'ESP32 Fingerprint',1359,'9e19a9c3bda3069deddf0ed2071ccd1a','71d0e10f1fa768d750c253258edb5aace8ce03285b04c32f21b786327bd2a860','2026-09-17 06:43:14'),(730,11,11,'2026-09-17','TIME_IN',1,1,'2026-09-17 06:44:10.000000','ucc-esp32-01',NULL,'ESP32 Fingerprint',1360,'4b29190620490f6ef78cea499343457f','973fdddf521c03eec83f9656a687dfc10d3c9f449794390fec71659663a485e0','2026-09-17 06:44:10');
/*!40000 ALTER TABLE `attendance_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `default_work_schedules`
--

DROP TABLE IF EXISTS `default_work_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `default_work_schedules` (
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `schedule_type` enum('Work','Off') NOT NULL DEFAULT 'Work',
  `shift_start` time DEFAULT NULL,
  `shift_end` time DEFAULT NULL,
  `break_minutes` smallint(5) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`day_of_week`),
  CONSTRAINT `chk_default_schedule_times` CHECK (`schedule_type` = 'Off' and `shift_start` is null and `shift_end` is null or `schedule_type` = 'Work' and `shift_start` is not null and `shift_end` is not null and `shift_start` <> `shift_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `default_work_schedules`
--

LOCK TABLES `default_work_schedules` WRITE;
/*!40000 ALTER TABLE `default_work_schedules` DISABLE KEYS */;
INSERT INTO `default_work_schedules` VALUES ('Monday','Work','08:00:00','17:00:00',60,'2026-09-06 05:45:41'),('Tuesday','Work','08:00:00','17:00:00',60,'2026-09-06 05:45:41'),('Wednesday','Work','08:00:00','17:00:00',60,'2026-09-06 05:45:41'),('Thursday','Work','08:00:00','17:00:00',60,'2026-09-06 05:45:41'),('Friday','Work','08:00:00','17:00:00',60,'2026-09-06 05:45:41'),('Saturday','Work','08:00:00','17:00:00',60,'2026-09-06 05:45:41'),('Sunday','Off',NULL,NULL,0,'2026-08-22 22:58:48');
/*!40000 ALTER TABLE `default_work_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'Admin Staff','ADMIN'),(2,'Faculty','FAC'),(3,'Finance','FIN'),(4,'Registrar','REG'),(5,'Maintenance','MNT');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_commands`
--

DROP TABLE IF EXISTS `device_commands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_commands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `command_uuid` varchar(64) NOT NULL,
  `command_type` enum('ENROLL','VERIFY_ATTENDANCE') NOT NULL,
  `employee_id` int(10) unsigned NOT NULL,
  `fingerprint_slot` int(10) unsigned NOT NULL,
  `event_type` enum('IN','OUT') DEFAULT NULL,
  `enrollment_version` int(10) unsigned DEFAULT NULL,
  `device_id` varchar(80) DEFAULT NULL,
  `status` enum('Pending','Running','Done','Failed') NOT NULL DEFAULT 'Pending',
  `result_message` varchar(255) DEFAULT NULL,
  `requested_by` int(10) unsigned DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `command_uuid` (`command_uuid`),
  KEY `idx_device_command_status` (`status`,`requested_at`),
  KEY `fk_device_command_employee` (`employee_id`),
  KEY `fk_device_command_user` (`requested_by`),
  CONSTRAINT `fk_device_command_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_device_command_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=276 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_commands`
--

LOCK TABLES `device_commands` WRITE;
/*!40000 ALTER TABLE `device_commands` DISABLE KEYS */;
INSERT INTO `device_commands` VALUES (1,'99f517313dee90fcbb2ff86457f2c60a','ENROLL',11,11,NULL,NULL,NULL,'Failed','Superseded by a newer enrollment request',1,'2026-08-21 20:59:48','2026-08-21 21:00:02'),(2,'c7e23eaacd2cdf13afb29c0ff2c120c8','ENROLL',11,11,NULL,NULL,NULL,'Failed','Superseded by a newer enrollment request',1,'2026-08-21 21:00:02','2026-08-21 21:01:09'),(3,'ec36cedd3bcd618c28ee9bf9b9d9f35d','ENROLL',11,11,NULL,NULL,NULL,'Failed','Superseded by a newer enrollment request',1,'2026-08-21 21:01:09','2026-08-21 21:01:37'),(4,'fc939b7df9dfb339170d3cdbf2314f6f','ENROLL',11,11,NULL,NULL,'ucc-esp32-01','Failed','Enrollment scan timeout',1,'2026-08-21 21:01:37','2026-08-22 07:08:06'),(6,'7f108a53aba192563132705394663228','ENROLL',1,1,NULL,NULL,'ucc-esp32-01','Failed','Enrollment scan timeout',1,'2026-08-22 07:11:30','2026-08-22 07:11:52'),(7,'f187cf88776ebfd2e2601d18bbfc1be1','ENROLL',1,1,NULL,NULL,'ucc-esp32-01','Done','Enrolled after two matching scans',1,'2026-08-22 07:12:01','2026-08-22 07:12:08'),(8,'512e4ce21ad2867c95b160a018463967','ENROLL',11,11,NULL,NULL,'ucc-esp32-01','Done','Enrolled after two matching scans',1,'2026-08-22 07:12:24','2026-08-22 07:12:32'),(9,'256ad3e1fe910965be56dcf8d4b2edcd','ENROLL',11,11,NULL,NULL,NULL,'Failed','Superseded by a newer enrollment request',1,'2026-08-22 07:14:03','2026-08-22 07:14:24'),(10,'4b3bf0736e7fc581f9a0b2e76d3f3aba','ENROLL',11,11,NULL,NULL,'ucc-esp32-01','Failed','Enrollment scan timeout',1,'2026-08-22 07:14:24','2026-08-22 07:23:03'),(11,'9a27b620f98ea2ff766b857285aa2cb6','ENROLL',11,11,NULL,NULL,'ucc-esp32-01','Done','Enrolled after two matching scans',1,'2026-08-22 07:28:15','2026-08-22 07:28:23'),(12,'d29f8c284b06762076254e04dea0ece04ae72934be9e01eb023a466d2665d62a','VERIFY_ATTENDANCE',11,11,'IN',NULL,'ucc-esp32-01','Failed','Wrong employee on scan 1',NULL,'2026-08-22 21:39:46','2026-08-22 21:39:54'),(13,'8d1cfc1bbd6e3cb8e90a6cb04596159c2807c723659a5175fc0fb7d085e6e2ed','VERIFY_ATTENDANCE',11,11,'OUT',NULL,'ucc-esp32-01','Failed','Two scans did not match',NULL,'2026-08-22 21:40:14','2026-08-22 21:40:21'),(14,'1021c149e1ccdfe9d991e0aede3cb784ee1304518f33ce4691ef3de77b8aceda','VERIFY_ATTENDANCE',1,1,'OUT',NULL,'ucc-esp32-01','Done','Verified twice. Time-out recorded at 9:40 PM.',NULL,'2026-08-22 21:40:33','2026-08-22 21:40:43'),(15,'52b3a3fe9a38b208030a5f54f14dc723b6e7a2c5a903ad8f7c3776649e40d401','VERIFY_ATTENDANCE',11,11,'OUT',NULL,'ucc-esp32-01','Failed','Wrong employee on scan 1',NULL,'2026-08-22 21:40:54','2026-08-22 21:41:01'),(16,'64c8c0f00d0fe882c83221266d5601e2428263c5d15f3a9407fa572e6fe68f2d','VERIFY_ATTENDANCE',11,11,'OUT',NULL,'ucc-esp32-01','Failed','Two scans did not match',NULL,'2026-08-22 21:41:14','2026-08-22 21:41:22'),(17,'8166f2c9e26fdc095e1e7eabd1f7a8f8110f6fb02c210a994759aed598694977','VERIFY_ATTENDANCE',1,1,'IN',NULL,'ucc-esp32-01','Done','Verified twice. Time-in already recorded at 7:13 AM.',NULL,'2026-08-22 22:27:14','2026-08-22 22:27:25'),(40,'4d069d017bf9408d5f586a7c8a2d4a353cf4e60d4ff53a7f6538c5a0f3796771','ENROLL',28,28,NULL,1,'ucc-esp32-01','Failed','Finger already belongs to slot 4',1,'2026-08-23 20:22:30','2026-08-23 20:22:40'),(41,'5872bdb8b4ab44b0453980a7584f3ecf6173098f6845a60df8c0a39ea396a45d','ENROLL',28,28,NULL,2,'ucc-esp32-01','Failed','Finger already belongs to slot 4',1,'2026-08-23 20:22:46','2026-08-23 20:22:56'),(57,'fdbb3a7c798e3c12d04b5d412821bf4522c3be3ea59f8bc2b94e56d3c812ee8d','ENROLL',28,28,NULL,3,'ucc-esp32-01','Done','Enrolled and verified at slot 28',1,'2026-08-23 21:10:00','2026-08-23 21:10:26'),(58,'7bd43246112e444a06c65291b23e2cb3e0b7af597f4e135ef9e244f5c624f557','VERIFY_ATTENDANCE',28,28,'IN',NULL,'ucc-esp32-01','Failed','Fingerprint not enrolled',1,'2026-08-23 21:12:33','2026-08-23 21:12:46'),(59,'f2c28807798ca3517cbbf1936b6eb7347567916802ec64ff6f6662ccc229ce0d','ENROLL',28,28,NULL,4,'ucc-esp32-01','Done','Enrolled and verified at slot 28',1,'2026-08-23 21:13:46','2026-08-23 21:14:01'),(69,'34f27e29e85c5b3346ddb095a2c1a4856c85d7fe6abf0153a686e3ebd634a330','ENROLL',1,1,NULL,1,'ucc-esp32-01','Done','Five thumb positions enrolled for profile 1',1,'2026-08-26 22:32:21','2026-08-26 22:34:27'),(79,'0f497bb505db20fe4268d02d2630b1a301196fc82c6b12053f0bafa0ae759a8b','ENROLL',1,1,NULL,2,'ucc-esp32-01','Failed','Could not check existing fingerprints; partial sensor profile cleared',1,'2026-08-27 22:54:37','2026-08-27 22:55:10'),(80,'d75e1755e899a4e05bf8f9718026a828565d105daf8f700219b0aa3adf716edc','ENROLL',1,1,NULL,3,'ucc-esp32-01','Failed','Could not check existing fingerprints; partial sensor profile cleared',1,'2026-08-27 22:55:21','2026-08-27 22:56:00'),(81,'4946981ff69e5215af135adc3df0c01a747a4b674c1be873db18087ce36ba490','ENROLL',1,1,NULL,4,'ucc-esp32-01','Done','Five thumb positions enrolled and verified for profile 1',1,'2026-08-27 22:56:07','2026-08-27 22:57:04'),(82,'1a54e5f67c95bb772389c75756aa92182d54cb245421fc3b7a83578eac3d1f0c','ENROLL',1,1,NULL,5,'ucc-esp32-01','Failed','Enrollment scan timeout',1,'2026-08-27 22:57:50','2026-08-27 22:58:16'),(83,'81b20b2f49583762f78932f2b608f00c5f60a56c30936aba64a5cbe52b53480b','ENROLL',1,1,NULL,6,'ucc-esp32-01','Failed','Could not check existing fingerprints',1,'2026-08-27 22:58:32','2026-08-27 22:58:46'),(84,'0774be2e1ad8f7ea17be0859d9304754146229b238321c30a02147d38d9c66a2','ENROLL',1,1,NULL,7,'ucc-esp32-01','Done','Five thumb positions enrolled and verified for profile 1',1,'2026-08-27 22:58:52','2026-08-27 22:59:47'),(85,'99dc6986e78439055a9276cf0181ad0bcccb4548bca51398761be31a33c10f19','ENROLL',11,11,NULL,1,'ucc-esp32-01','Failed','Could not check existing fingerprints; partial sensor profile cleared',1,'2026-08-27 23:00:14','2026-08-27 23:00:35'),(86,'3476547efa5f0321177e06bb2332218036d74b4c4c9b7026571a2c2ae0e9acbd','ENROLL',11,11,NULL,2,'ucc-esp32-01','Failed','Could not check existing fingerprints; partial sensor profile cleared',1,'2026-08-27 23:00:41','2026-08-27 23:01:04'),(87,'ff07bffe4e1a69891dffa51190f98d5c42a7d92c4030ddde46089ea071206a1c','ENROLL',11,11,NULL,3,'ucc-esp32-01','Failed','Could not check existing fingerprints; partial sensor profile cleared',1,'2026-08-27 23:01:10','2026-08-27 23:01:57'),(88,'c40044d87b567b7c8144fd8cc6a0fde4600d8f74c835fd293f52ab120e9e319c','ENROLL',11,11,NULL,4,'ucc-esp32-01','Failed','Could not check existing fingerprints',1,'2026-08-27 23:02:03','2026-08-27 23:02:13'),(89,'34e82dcf5397c30627e9e6f791309b7db50fb3817e26ae54f55bc3c3bce5e78b','ENROLL',11,11,NULL,5,'ucc-esp32-01','Failed','Could not check existing fingerprints; partial sensor profile cleared',1,'2026-08-27 23:02:18','2026-08-27 23:02:55'),(90,'039ae956cc207a21ee747c22f498099f84ea9b710d8ca6889ae380d5433da617','ENROLL',11,11,NULL,6,'ucc-esp32-01','Failed','Could not check existing fingerprints; partial sensor profile cleared',1,'2026-08-27 23:03:01','2026-08-27 23:03:21'),(91,'38dbe185e7e94ab8eab29cc20b8f98c78dc9174f3a90dfc45545c3e3de943b8e','ENROLL',11,11,NULL,7,'ucc-esp32-01','Done','Five thumb positions enrolled and verified for profile 11',1,'2026-08-27 23:03:34','2026-08-27 23:04:22'),(92,'6af1f57864aa4ff3f0f88cb01edfae1e921e1ab3f507a298b0e5fd1ca4056e3a','ENROLL',28,28,NULL,5,'ucc-esp32-01','Done','Five thumb positions enrolled and verified for profile 28',1,'2026-08-27 23:05:00','2026-08-27 23:06:27'),(93,'029265094008681e082881674f64fe8976325440e56a35e551cd68acd0f91a48','ENROLL',28,28,NULL,6,'ucc-esp32-01','Failed','Could not check existing fingerprints; partial sensor profile cleared',1,'2026-08-27 23:07:05','2026-08-27 23:07:55'),(100,'7ef13a52b1b52a140562a4ff67926ea573e821b9b787fe93fab65d0969ac0a11','ENROLL',28,28,NULL,7,'ucc-esp32-01','Failed','Remove finger timeout; partial sensor profile cleared',1,'2026-08-27 23:44:28','2026-08-27 23:45:32'),(101,'95fd2994465b6645988a6924d756777c4526a5330ffcde3af29041bd86389385','ENROLL',28,28,NULL,8,'ucc-esp32-01','Done','Five thumb positions enrolled and verified for profile 28',1,'2026-08-27 23:46:02','2026-08-27 23:47:35'),(126,'12eacdca0c383b73f7080c1a2cfa7c16f99a4b54aa9535ff3a5c6a4e702199f5','ENROLL',11,11,NULL,8,'ucc-esp32-01','Failed','Enrollment cancelled at biometric terminal by BTN2',1,'2026-09-06 10:52:56','2026-09-06 10:53:04'),(127,'a716d57bbe4395dc96d7a69036256f58e80848400182056cd2eba8a495af56a4','ENROLL',11,11,NULL,9,'ucc-esp32-01','Failed','Sensor needs a fresh thumb placement for this angle',1,'2026-09-06 10:53:09','2026-09-06 10:53:46'),(128,'e1801697706d7363806f80174dbeafa4704d3de1b2e7bc40c6dcd4af566d9713','ENROLL',11,11,NULL,10,'ucc-esp32-01','Done','Five thumb positions enrolled and verified for profile 11',1,'2026-09-06 10:53:55','2026-09-06 10:55:24'),(129,'9d66acc9b9dc45432300deed5a5d3d0af10e20df593f74efd97ec66c10924c40','ENROLL',28,28,NULL,9,'ucc-esp32-01','Failed','Sensor needs a fresh thumb placement for this angle',1,'2026-09-06 10:55:50','2026-09-06 10:56:22'),(130,'c7702c8e9ca5505316a11f9a99986851876c934442a843c0ac76754c8d2bc6e7','ENROLL',28,28,NULL,10,'ucc-esp32-01','Failed','Position did not match the same employee thumb; partial sensor profile cleared',1,'2026-09-06 10:56:29','2026-09-06 10:57:36'),(131,'504b0177e2b9f69bdaf3f8a913bade366886a289a4e1f1480d94e19b224f4dd7','ENROLL',28,28,NULL,11,'ucc-esp32-01','Done','Five thumb positions enrolled and verified for profile 28',1,'2026-09-06 10:57:41','2026-09-06 10:59:06'),(138,'6394615249b95794b3b66a59ba7ea93712e2800066afd75fd250ae896e526899','ENROLL',1,1,NULL,8,'ucc-esp32-01','Done','Five thumb positions enrolled and verified for profile 1',1,'2026-09-06 11:35:15','2026-09-06 11:36:28'),(154,'3000749ccd0fdd0405c69142dd6bab285147e7015e64e5508ee105ee5c3dd302','ENROLL',154,15,NULL,1,'ucc-esp32-01','Failed','Enrollment cancelled at biometric terminal by BTN2',1,'2026-09-07 17:51:05','2026-09-10 19:00:03'),(239,'0f8570e3ac4b292f84b2c6185c7c0d734f41ed484a15b46396e3291362ef0f4d','ENROLL',154,15,NULL,2,'ucc-esp32-01','Failed','Enrollment cancelled at biometric terminal by BTN2',1,'2026-09-14 16:25:37','2026-09-14 16:25:50');
/*!40000 ALTER TABLE `device_commands` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_nonces`
--

DROP TABLE IF EXISTS `device_nonces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_nonces` (
  `device_id` varchar(80) NOT NULL,
  `nonce` varchar(80) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`device_id`,`nonce`),
  KEY `idx_device_nonce_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_nonces`
--

LOCK TABLES `device_nonces` WRITE;
/*!40000 ALTER TABLE `device_nonces` DISABLE KEYS */;
INSERT INTO `device_nonces` VALUES ('ucc-esp32-01','f5b4d8dece876a9f002cdeb92388a285','2026-09-17 06:41:58'),('ucc-esp32-01','f1441970f4884471137973d5a5ebdf8b','2026-09-17 06:42:24'),('ucc-esp32-01','fee30087a001ad11722de5de03c62a04','2026-09-17 06:42:40'),('ucc-esp32-01','f7951ddef2632bf8f51c3c13f9df2870','2026-09-17 06:43:07'),('ucc-esp32-01','ff76d7efb8c8bb2201a3fd6767e67486','2026-09-17 06:43:29'),('ucc-esp32-01','fb40fe98440828dc2c61ef127e509172','2026-09-17 06:44:51'),('ucc-esp32-01','f6b8e9e40701c72f5d2beff64918f11f','2026-09-17 06:44:53'),('ucc-esp32-01','fb7074222b5f2d20badbd0a5c1fdd011','2026-09-17 06:45:37'),('ucc-esp32-01','f23128e0290f90812e0b27b4645610b0','2026-09-17 06:48:24'),('ucc-esp32-01','f65218811a7aeb3500b7b2d0229d2707','2026-09-17 06:48:55'),('ucc-esp32-01','f5ee277db5c70f8fc2b8274fde8beabe','2026-09-17 06:49:23'),('ucc-esp32-01','fd78aca6f5996e046f5b2edcfcb6dd7a','2026-09-17 06:50:01'),('ucc-esp32-01','f3864f1c15037fdfa886d4e71db78968','2026-09-17 06:50:26'),('ucc-esp32-01','f6b14715f3b5b6edd323ac6579ce3e96','2026-09-17 06:51:02'),('ucc-esp32-01','f3ebde4d7575b9f7be65e06822e2a760','2026-09-17 06:52:35'),('ucc-esp32-01','f0de90fcf0b9eff93996facfd10c9411','2026-09-17 06:52:41'),('ucc-esp32-01','fc99e1c5db852520eb7ef80d9d96e00f','2026-09-17 06:53:03'),('ucc-esp32-01','fa2c485f56dd7250903b6ed801293d24','2026-09-17 06:53:55'),('ucc-esp32-01','f109d23622230f9d038c9d0564775879','2026-09-17 06:53:59'),('ucc-esp32-01','fff3404eb9448f5a100c718afec6ecbc','2026-09-17 06:54:31'),('ucc-esp32-01','f6dbf1e1b3a8cf80cf14cb2acf9058fa','2026-09-17 06:54:35'),('ucc-esp32-01','f2a52cfc2ba4f0040d4e8def15dd0128','2026-09-17 06:55:18'),('ucc-esp32-01','f934cf6c7a3056d76fbab87b9adb8c94','2026-09-17 06:56:24'),('ucc-esp32-01','f4295c0a80ba0d762eb7367bfacfba90','2026-09-17 06:57:16'),('ucc-esp32-01','f21972e1c80809f8a006a76258ebef6c','2026-09-17 06:57:56'),('ucc-esp32-01','f3266be5bda43a30f6c0c6b6c60a02f8','2026-09-17 06:58:39'),('ucc-esp32-01','f4ed62ddf9e73b673c4c1377a4456be8','2026-09-17 06:58:49'),('ucc-esp32-01','ce00bd0eec2bd2c5c2bbf192134232a2','2026-09-19 21:35:23');
/*!40000 ALTER TABLE `device_nonces` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_status`
--

DROP TABLE IF EXISTS `device_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_status` (
  `device_id` varchar(80) NOT NULL,
  `last_seen` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `firmware_version` varchar(40) DEFAULT NULL,
  `last_message` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`device_id`),
  KEY `idx_device_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_status`
--

LOCK TABLES `device_status` WRITE;
/*!40000 ALTER TABLE `device_status` DISABLE KEYS */;
INSERT INTO `device_status` VALUES ('ucc-esp32-01','2026-09-19 21:35:23','192.168.1.6','2026.09.14-security-stability-v12','Capabilities: enroll-btn1-v1, enroll-five-template-v1');
/*!40000 ALTER TABLE `device_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_account_tokens`
--

DROP TABLE IF EXISTS `employee_account_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_account_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_account_id` bigint(20) unsigned NOT NULL,
  `purpose` enum('Activation','Reset') NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_account_token_hash` (`token_hash`),
  KEY `idx_employee_account_token_lookup` (`employee_account_id`,`purpose`,`used_at`,`expires_at`),
  KEY `idx_employee_account_token_creator` (`created_by`),
  CONSTRAINT `fk_employee_account_token_account` FOREIGN KEY (`employee_account_id`) REFERENCES `employee_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_employee_account_token_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_account_tokens`
--

LOCK TABLES `employee_account_tokens` WRITE;
/*!40000 ALTER TABLE `employee_account_tokens` DISABLE KEYS */;
INSERT INTO `employee_account_tokens` VALUES (6,1,'Activation','618b53504ab0d91218f3183637b6241a545bf97b10cfe7d4c1a6750000cb808e','2026-08-31 08:09:22','2026-08-30 08:10:02',1,'2026-08-30 08:09:22','2026-08-30 08:10:02');
/*!40000 ALTER TABLE `employee_account_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_accounts`
--

DROP TABLE IF EXISTS `employee_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `account_status` enum('Pending','Active','Disabled') NOT NULL DEFAULT 'Pending',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `failed_attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `reset_requested_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_account_employee` (`employee_id`),
  UNIQUE KEY `uq_employee_account_username` (`username`),
  KEY `idx_employee_account_status` (`account_status`),
  KEY `idx_employee_account_created_by` (`created_by`),
  CONSTRAINT `fk_employee_account_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_employee_account_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_accounts`
--

LOCK TABLES `employee_accounts` WRITE;
/*!40000 ALTER TABLE `employee_accounts` DISABLE KEYS */;
INSERT INTO `employee_accounts` VALUES (1,1,'EMP-0010','$2y$10$cGogS.TJYDEJKIdFevA4k./nk9BsbVI/M4ZatBTy4Nym9kexorp3q','Active',0,0,NULL,'2026-09-16 15:53:32','2026-09-04 13:53:50',NULL,NULL,'2026-08-30 07:45:45','2026-09-16 15:53:32'),(2,11,'EMP-1002','$2y$10$CFH3XnQE.pkYo3MysO3a.ep0BjpaDrZzVtmY6G7ag3ei.rD8Abv5q','Active',0,0,NULL,'2026-08-30 08:14:42','2026-08-30 08:16:01',NULL,NULL,'2026-08-30 07:45:45','2026-08-30 08:16:01'),(3,28,'EMP-1012','$2y$10$3IDrc6Fp8gLcHSEsP3bKLeT8VAgMTTx8j7ltQP/J0cW3rPCrQCR.6','Active',0,0,NULL,'2026-08-30 08:17:11','2026-08-30 08:17:36',NULL,NULL,'2026-08-30 07:45:45','2026-08-30 08:17:36'),(4,154,'EMP-1029','$2y$10$XEzlKApgDN2PfwdNtEFea.A.zJSdPHnzPbOK/Q5bDXHsdza.AqVZO','Active',0,0,NULL,'2026-09-07 17:54:16','2026-09-07 17:55:19',NULL,1,'2026-09-07 17:50:53','2026-09-07 17:55:19');
/*!40000 ALTER TABLE `employee_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_compensation_history`
--

DROP TABLE IF EXISTS `employee_compensation_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_compensation_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `employment_type` enum('Full-Time','Part-Time') DEFAULT NULL,
  `pay_type` enum('Daily','Hourly','Monthly') NOT NULL,
  `basic_rate` decimal(12,2) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `change_reason` varchar(500) DEFAULT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_compensation_employee_effective` (`employee_id`,`effective_from`),
  KEY `idx_compensation_employee_period` (`employee_id`,`effective_from`,`effective_to`),
  KEY `fk_compensation_user` (`changed_by`),
  CONSTRAINT `fk_compensation_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_compensation_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_compensation_history`
--

LOCK TABLES `employee_compensation_history` WRITE;
/*!40000 ALTER TABLE `employee_compensation_history` DISABLE KEYS */;
INSERT INTO `employee_compensation_history` VALUES (1,1,NULL,'Daily',650.00,'2026-08-21','2026-09-05','Legacy compensation migrated from employees.daily_rate',NULL,'2026-09-06 05:45:40'),(2,11,NULL,'Daily',650.00,'2026-08-21','2026-09-05','Legacy compensation migrated from employees.daily_rate',NULL,'2026-09-06 05:45:40'),(3,28,NULL,'Daily',650.00,'2026-08-23','2026-09-05','Legacy compensation migrated from employees.daily_rate',NULL,'2026-09-06 05:45:40'),(4,1,'Full-Time','Daily',21.67,'2026-09-06','2026-09-09','Full-time admin staff',1,'2026-09-06 10:25:24'),(5,11,'Full-Time','Daily',21.67,'2026-09-06','2026-09-09','Full-time finance',1,'2026-09-06 10:29:08'),(6,28,'Part-Time','Daily',1200.00,'2026-09-06','2026-09-09','Part-time',1,'2026-09-06 11:00:14'),(7,154,'Full-Time','Daily',450.00,'2026-09-07',NULL,'Initial approved compensation',1,'2026-09-07 17:50:53'),(8,1,'Full-Time','Daily',650.00,'2026-09-10',NULL,'change to daily',1,'2026-09-10 07:31:09'),(9,11,'Full-Time','Daily',650.00,'2026-09-10',NULL,'change to daily',1,'2026-09-10 07:31:29'),(10,28,'Part-Time','Daily',300.00,'2026-09-10','2026-09-11','Part-time',1,'2026-09-10 20:06:38'),(11,28,'Part-Time','Hourly',150.00,'2026-09-12',NULL,'no reason',1,'2026-09-12 11:50:28');
/*!40000 ALTER TABLE `employee_compensation_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_deduction_entries`
--

DROP TABLE IF EXISTS `employee_deduction_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_deduction_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `deduction_date` date NOT NULL,
  `deduction_type` enum('Cash Advance','Other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `status` enum('Active','Cancelled') NOT NULL DEFAULT 'Active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_deduction_employee_date` (`employee_id`,`deduction_date`,`status`),
  KEY `fk_deduction_creator` (`created_by`),
  CONSTRAINT `fk_deduction_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deduction_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_deduction_entries`
--

LOCK TABLES `employee_deduction_entries` WRITE;
/*!40000 ALTER TABLE `employee_deduction_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_deduction_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_monthly_schedules`
--

DROP TABLE IF EXISTS `employee_monthly_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_monthly_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `schedule_month` date NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `schedule_type` enum('Work','Off') NOT NULL DEFAULT 'Off',
  `shift_start` time DEFAULT NULL,
  `shift_end` time DEFAULT NULL,
  `break_minutes` smallint(5) unsigned NOT NULL DEFAULT 0,
  `schedule_periods` longtext DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_monthly_schedule` (`employee_id`,`schedule_month`,`day_of_week`),
  KEY `idx_monthly_schedule_month` (`schedule_month`,`employee_id`),
  KEY `fk_monthly_schedule_creator` (`created_by`),
  CONSTRAINT `fk_monthly_schedule_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_monthly_schedule_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_monthly_schedules`
--

LOCK TABLES `employee_monthly_schedules` WRITE;
/*!40000 ALTER TABLE `employee_monthly_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_monthly_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_no` varchar(30) NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(30) NOT NULL DEFAULT 'Not specified',
  `civil_status` varchar(30) NOT NULL DEFAULT 'Single',
  `department_id` int(10) unsigned DEFAULT NULL,
  `position` varchar(120) NOT NULL,
  `employment_type` enum('Full-Time','Part-Time') DEFAULT NULL,
  `pay_type` enum('Daily','Hourly','Monthly') NOT NULL DEFAULT 'Daily',
  `basic_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `daily_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `contact_number` varchar(40) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `status` enum('Active','Inactive','On leave') NOT NULL DEFAULT 'Active',
  `fingerprint_status` enum('Enrolled','Not enrolled') NOT NULL DEFAULT 'Not enrolled',
  `fingerprint_code` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_no` (`employee_no`),
  UNIQUE KEY `uq_employee_fingerprint_code` (`fingerprint_code`),
  KEY `fk_employee_department` (`department_id`),
  CONSTRAINT `fk_employee_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=303 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES (1,'EMP-0010','Jules Ivan','','Zafra','2005-07-07','Male','Single',1,'Admin Aide','Full-Time','Daily',650.00,650.00,'0912 345 6789','j.zafra@ucchr.edu.ph','Active','Enrolled','1','2026-08-21 10:04:57','2026-09-10 07:31:09'),(11,'EMP-1002','Grey','A','Zafra','2004-02-02','Female','Single',3,'Finance executive','Full-Time','Daily',650.00,650.00,'87000900','grey@ucchr.edu.ph','Active','Enrolled','11','2026-08-21 20:59:48','2026-09-10 07:31:29'),(28,'EMP-1012','Navi','B','Sukihero','2005-01-01','Male','Single',5,'Maintenance staff','Part-Time','Hourly',150.00,1200.00,'09271127064','Navi@ucchr.edu.ph','Active','Enrolled','28','2026-08-23 20:22:13','2026-09-12 11:50:28'),(154,'EMP-1029','Cristine Kate','','Cadoldolan','2005-01-01','Female','Single',3,'Finance officer','Full-Time','Daily',450.00,450.00,'090711363884','Ck@gmail.com','Active','Not enrolled','15','2026-09-07 17:50:53','2026-09-07 17:50:53');
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fingerprint_registrations`
--

DROP TABLE IF EXISTS `fingerprint_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fingerprint_registrations` (
  `employee_id` int(10) unsigned NOT NULL,
  `fingerprint_slot` smallint(5) unsigned NOT NULL,
  `mapping_status` enum('Reserved','Pending','Enrolled','Failed') NOT NULL DEFAULT 'Reserved',
  `enrollment_version` int(10) unsigned NOT NULL DEFAULT 0,
  `enrollment_command_uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(80) DEFAULT NULL,
  `enrolled_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `uq_fingerprint_registration_slot` (`fingerprint_slot`),
  UNIQUE KEY `uq_fingerprint_registration_command` (`enrollment_command_uuid`),
  CONSTRAINT `fk_fingerprint_registration_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_fingerprint_registration_slot` CHECK (`fingerprint_slot` between 1 and 127)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fingerprint_registrations`
--

LOCK TABLES `fingerprint_registrations` WRITE;
/*!40000 ALTER TABLE `fingerprint_registrations` DISABLE KEYS */;
INSERT INTO `fingerprint_registrations` VALUES (1,1,'Enrolled',8,'6394615249b95794b3b66a59ba7ea93712e2800066afd75fd250ae896e526899','ucc-esp32-01','2026-09-06 11:36:28','2026-09-06 11:36:28'),(11,11,'Enrolled',10,'e1801697706d7363806f80174dbeafa4704d3de1b2e7bc40c6dcd4af566d9713','ucc-esp32-01','2026-09-06 10:55:24','2026-09-06 10:55:24'),(28,28,'Enrolled',11,'504b0177e2b9f69bdaf3f8a913bade366886a289a4e1f1480d94e19b224f4dd7','ucc-esp32-01','2026-09-06 10:59:06','2026-09-06 10:59:06'),(154,15,'Reserved',2,'0f8570e3ac4b292f84b2c6185c7c0d734f41ed484a15b46396e3291362ef0f4d',NULL,NULL,'2026-09-14 16:25:37');
/*!40000 ALTER TABLE `fingerprint_registrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fingerprint_template_slots`
--

DROP TABLE IF EXISTS `fingerprint_template_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fingerprint_template_slots` (
  `employee_id` int(10) unsigned NOT NULL,
  `position` enum('CENTER','LEFT','RIGHT','UPPER','LOWER') NOT NULL,
  `sensor_slot` smallint(5) unsigned NOT NULL,
  `mapping_status` enum('Reserved','Pending','Enrolled','Failed') NOT NULL DEFAULT 'Reserved',
  `enrollment_version` int(10) unsigned NOT NULL DEFAULT 0,
  `device_id` varchar(80) DEFAULT NULL,
  `enrolled_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`employee_id`,`position`),
  UNIQUE KEY `uq_fingerprint_template_sensor_slot` (`sensor_slot`),
  KEY `idx_fingerprint_template_employee_status` (`employee_id`,`mapping_status`),
  CONSTRAINT `fk_fingerprint_template_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fingerprint_template_slots`
--

LOCK TABLES `fingerprint_template_slots` WRITE;
/*!40000 ALTER TABLE `fingerprint_template_slots` DISABLE KEYS */;
INSERT INTO `fingerprint_template_slots` VALUES (1,'CENTER',1,'Enrolled',8,'ucc-esp32-01','2026-09-06 11:36:28','2026-09-06 11:36:28'),(1,'LEFT',10,'Enrolled',8,'ucc-esp32-01','2026-09-06 11:36:28','2026-09-06 11:36:28'),(1,'RIGHT',12,'Enrolled',8,'ucc-esp32-01','2026-09-06 11:36:28','2026-09-06 11:36:28'),(1,'UPPER',13,'Enrolled',8,'ucc-esp32-01','2026-09-06 11:36:28','2026-09-06 11:36:28'),(1,'LOWER',14,'Enrolled',8,'ucc-esp32-01','2026-09-06 11:36:28','2026-09-06 11:36:28'),(11,'CENTER',11,'Enrolled',10,'ucc-esp32-01','2026-09-06 10:55:24','2026-09-06 10:55:24'),(11,'LEFT',2,'Enrolled',10,'ucc-esp32-01','2026-09-06 10:55:24','2026-09-06 10:55:24'),(11,'RIGHT',3,'Enrolled',10,'ucc-esp32-01','2026-09-06 10:55:24','2026-09-06 10:55:24'),(11,'UPPER',4,'Enrolled',10,'ucc-esp32-01','2026-09-06 10:55:24','2026-09-06 10:55:24'),(11,'LOWER',5,'Enrolled',10,'ucc-esp32-01','2026-09-06 10:55:24','2026-09-06 10:55:24'),(28,'CENTER',28,'Enrolled',11,'ucc-esp32-01','2026-09-06 10:59:06','2026-09-06 10:59:06'),(28,'LEFT',6,'Enrolled',11,'ucc-esp32-01','2026-09-06 10:59:06','2026-09-06 10:59:06'),(28,'RIGHT',7,'Enrolled',11,'ucc-esp32-01','2026-09-06 10:59:06','2026-09-06 10:59:06'),(28,'UPPER',8,'Enrolled',11,'ucc-esp32-01','2026-09-06 10:59:06','2026-09-06 10:59:06'),(28,'LOWER',9,'Enrolled',11,'ucc-esp32-01','2026-09-06 10:59:06','2026-09-06 10:59:06'),(154,'CENTER',15,'Reserved',2,NULL,NULL,'2026-09-14 16:25:37'),(154,'LEFT',16,'Reserved',2,NULL,NULL,'2026-09-14 16:25:37'),(154,'RIGHT',17,'Reserved',2,NULL,NULL,'2026-09-14 16:25:37'),(154,'UPPER',18,'Reserved',2,NULL,NULL,'2026-09-14 16:25:37'),(154,'LOWER',19,'Reserved',2,NULL,NULL,'2026-09-14 16:25:37');
/*!40000 ALTER TABLE `fingerprint_template_slots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `holidays`
--

DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `holiday_name` varchar(160) NOT NULL,
  `holiday_date` date NOT NULL,
  `holiday_type` enum('Regular Holiday','Special Non-Working Day') NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_holiday_date` (`holiday_date`),
  KEY `idx_holiday_status_date` (`status`,`holiday_date`),
  KEY `fk_holiday_creator` (`created_by`),
  CONSTRAINT `fk_holiday_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `holidays`
--

LOCK TABLES `holidays` WRITE;
/*!40000 ALTER TABLE `holidays` DISABLE KEYS */;
INSERT INTO `holidays` VALUES (1,'Bonifacio Day','2026-11-30','Regular Holiday',NULL,'Active',1,'2026-09-06 17:10:36','2026-09-06 17:10:36'),(2,'Christmas Day','2026-12-25','Regular Holiday',NULL,'Active',1,'2026-09-06 17:12:01','2026-09-06 17:12:01'),(3,'Rizal Day','2026-12-30','Regular Holiday',NULL,'Active',1,'2026-09-06 17:12:36','2026-09-06 17:12:36');
/*!40000 ALTER TABLE `holidays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `leave_type` enum('Paid Leave','Unpaid Leave') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` varchar(1000) NOT NULL,
  `status` enum('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
  `approved_by` int(10) unsigned DEFAULT NULL,
  `date_approved` datetime DEFAULT NULL,
  `decision_note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leave_employee_dates` (`employee_id`,`start_date`,`end_date`),
  KEY `idx_leave_status_dates` (`status`,`start_date`,`end_date`),
  KEY `fk_leave_approver` (`approved_by`),
  CONSTRAINT `fk_leave_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leave_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_requests`
--

LOCK TABLES `leave_requests` WRITE;
/*!40000 ALTER TABLE `leave_requests` DISABLE KEYS */;
INSERT INTO `leave_requests` VALUES (1,1,'Paid Leave','2026-09-07','2026-09-08','Labad akong ulo','Approved',1,'2026-09-07 17:13:55','okay','2026-09-07 17:13:34','2026-09-07 17:13:55');
/*!40000 ALTER TABLE `leave_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `overtime_requests`
--

DROP TABLE IF EXISTS `overtime_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `overtime_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `attendance_id` bigint(20) unsigned DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `potential_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `requested_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `approved_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
  `request_source` enum('Automatic','Employee','Administrator') NOT NULL DEFAULT 'Employee',
  `reason` varchar(1000) DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approval_date` datetime DEFAULT NULL,
  `decision_note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_overtime_employee_date` (`employee_id`,`attendance_date`),
  KEY `idx_overtime_status_date` (`status`,`attendance_date`),
  KEY `idx_overtime_attendance` (`attendance_id`),
  KEY `fk_overtime_approver` (`approved_by`),
  CONSTRAINT `fk_overtime_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_overtime_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_overtime_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=190 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `overtime_requests`
--

LOCK TABLES `overtime_requests` WRITE;
/*!40000 ALTER TABLE `overtime_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `overtime_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_item_attendance`
--

DROP TABLE IF EXISTS `payroll_item_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_item_attendance` (
  `payroll_item_id` bigint(20) unsigned NOT NULL,
  `attendance_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payroll_item_id`,`attendance_id`),
  UNIQUE KEY `uq_payroll_consumed_attendance` (`attendance_id`),
  CONSTRAINT `fk_payroll_link_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`),
  CONSTRAINT `fk_payroll_link_item` FOREIGN KEY (`payroll_item_id`) REFERENCES `payroll_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_item_attendance`
--

LOCK TABLES `payroll_item_attendance` WRITE;
/*!40000 ALTER TABLE `payroll_item_attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_item_attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_items`
--

DROP TABLE IF EXISTS `payroll_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payroll_run_id` int(10) unsigned NOT NULL,
  `employee_id` int(10) unsigned NOT NULL,
  `employment_type` varchar(20) DEFAULT NULL,
  `pay_type` varchar(20) NOT NULL DEFAULT 'Daily',
  `basic_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `days_worked` decimal(6,2) NOT NULL,
  `regular_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `regular_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
  `hourly_equivalent_rate` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `monthly_basic_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `regular_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `late_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `late_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `undertime_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `undertime_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `half_day_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `absence_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cash_advance_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `absence_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `unpaid_leave_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `approved_overtime_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `overtime_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `ot_rate` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `overtime_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `holiday_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
  `holiday_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rest_day_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rest_day_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_pay` decimal(12,2) NOT NULL,
  `total_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_pay` decimal(12,2) NOT NULL,
  `calculation_snapshot` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_employee` (`payroll_run_id`,`employee_id`),
  KEY `fk_item_employee` (`employee_id`),
  CONSTRAINT `fk_item_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_run` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_items`
--

LOCK TABLES `payroll_items` WRITE;
/*!40000 ALTER TABLE `payroll_items` DISABLE KEYS */;
INSERT INTO `payroll_items` VALUES (10,2,1,NULL,'Daily',650.00,5.00,0,0.00,0.0000,3250.00,3250.00,0,0.00,0,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,4.75,0.0000,482.42,0.00,0.00,0.00,0.00,0.00,3732.42,0.00,3732.42,NULL),(11,2,11,NULL,'Daily',650.00,5.00,0,0.00,0.0000,3250.00,3250.00,0,0.00,0,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,0.10,0.0000,10.16,0.00,0.00,0.00,0.00,0.00,3260.16,0.00,3260.16,NULL),(12,2,28,NULL,'Daily',650.00,3.00,0,0.00,0.0000,1950.00,1950.00,0,0.00,0,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,0.00,0.0000,0.00,0.00,0.00,0.00,0.00,0.00,1950.00,0.00,1950.00,NULL);
/*!40000 ALTER TABLE `payroll_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_runs`
--

DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `scope_employee_id` int(10) unsigned DEFAULT NULL,
  `processed_by` int(10) unsigned DEFAULT NULL,
  `processed_at` datetime NOT NULL,
  `status` enum('Draft','For Review','Approved','Finalized','Paid','Released') NOT NULL DEFAULT 'Draft',
  `payment_method` enum('Cash','Bank Transfer') NOT NULL DEFAULT 'Cash',
  `payment_status` enum('Pending','Paid') NOT NULL DEFAULT 'Pending',
  `payment_updated_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `finalized_by` int(10) unsigned DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `policy_snapshot` longtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_scope_period` (`scope_employee_id`,`period_start`,`period_end`),
  KEY `fk_payroll_user` (`processed_by`),
  KEY `idx_payroll_status_period` (`status`,`period_end`),
  KEY `idx_payroll_period` (`period_start`,`period_end`),
  KEY `idx_payroll_payment_status` (`payment_status`,`period_end`),
  CONSTRAINT `fk_payroll_user` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_runs`
--

LOCK TABLES `payroll_runs` WRITE;
/*!40000 ALTER TABLE `payroll_runs` DISABLE KEYS */;
INSERT INTO `payroll_runs` VALUES (2,'2026-08-15','2026-08-30',NULL,1,'2026-08-30 07:09:04','Released','Cash','Paid','2026-08-30 07:09:04',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-10 07:11:06');
/*!40000 ALTER TABLE `payroll_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `migration_key` varchar(120) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schema_migrations`
--

LOCK TABLES `schema_migrations` WRITE;
/*!40000 ALTER TABLE `schema_migrations` DISABLE KEYS */;
INSERT INTO `schema_migrations` VALUES ('2026-09-05-integrated-attendance-payroll-v1','2026-09-06 05:45:41','Additive raw attendance, approvals, configurable payroll, and audit foundation'),('2026-09-06-flexible-part-time-periods-v1','2026-09-06 17:51:48','Add multiple daily schedule periods and immutable attendance period snapshots'),('2026-09-06-remove-obsolete-deductions-v1','2026-09-06 21:24:52','Keep Late as the only deduction and remove obsolete statutory/broad deduction fields'),('2026-09-06-retire-admin-reset-tokens-v1','2026-09-06 21:32:47','Remove unused administrator reset-token storage; retain verified current-password recovery'),('2026-09-09-payroll-review-automation-v1','2026-09-09 22:23:41','Monthly expected schedules, schedule-based day classification, explicit source deductions, and transparent payroll review'),('2026-09-10-fixed-daily-rate-payroll-v1','2026-09-10 19:52:58','Approved Daily Rate with fixed 30-day monthly basic salary and immutable payroll-item snapshot'),('2026-09-10-payroll-dashboard-v1','2026-09-10 07:11:06','Add employee-scoped payroll generation and synchronized payment details'),('2026-09-11-attendance-status-payroll-v1','2026-09-11 07:02:20','Schedule-derived Present/Half-Day/Absent status with frozen absence and unpaid-day payroll deductions'),('2026-09-11-flexible-pay-types-v1','2026-09-11 06:32:46','Daily x 30, schedule-based Hourly, and configured Monthly salary calculations with immutable payroll snapshots'),('2026-09-13-multi-session-attendance-v1','2026-09-13 14:16:50','Ordered schedule sessions, four-punch Full-Time attendance, flexible Part-Time punches, and manual-only overtime requests'),('2026-09-13-multi-session-attendance-v2','2026-09-13 20:19:08','Ordered Full-Time sessions, flexible Part-Time punches, protected legacy days, and manual-only overtime approvals'),('2026-09-14-admin-auth-security-v1','2026-09-14 13:33:39','Fail-closed CSRF regression coverage and persistent administrator authentication throttling');
/*!40000 ALTER TABLE `schema_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `key` varchar(80) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES ('attendance_due_finalize_at','2026-09-17 07:00:01'),('break_duration','60'),('currency','PHP'),('device_shared_secret','cc0f57a786419ba853c3e5c7902f3ef9011104a0bcd903935ce92b0adc063111'),('employee_overtime_requests_enabled','1'),('full_day_minimum_percent','75'),('grace_minutes','15'),('half_day_minimum_percent','50'),('institution_name','Ubay Community College'),('late_deduction_enabled','1'),('overtime_enabled','1'),('overtime_multiplier','1.25'),('overtime_requires_approval','1'),('payroll_day','30'),('payroll_frequency','Semi-monthly'),('regular_holiday_overtime_multiplier','1'),('regular_holiday_rest_day_multiplier','1'),('regular_holiday_worked_multiplier','1'),('regular_hours_per_day','8'),('rest_day_multiplier','1'),('rounding_rule','nearest_cent'),('special_day_overtime_multiplier','1'),('special_day_rest_day_multiplier','1'),('special_day_worked_multiplier','1'),('timezone','Asia/Manila'),('undertime_deduction_enabled','1'),('working_day_basis','30');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `username` varchar(80) NOT NULL,
  `email` varchar(160) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(60) NOT NULL DEFAULT 'Administrator',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  CONSTRAINT `chk_users_password_hash` CHECK (char_length(`password_hash`) between 20 and 255 and left(`password_hash`,1) = '$')
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'JIZ_HR','admin','admin@ucchr.edu.ph','$2y$10$b8BP0BAlLJ2EY/5ewXfcvO6dlvNkFI4/ovmsP2jYICuI731mX6BjG','Administrator','2026-09-19 21:11:20','2026-08-21 10:04:57');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `work_schedule_periods`
--

DROP TABLE IF EXISTS `work_schedule_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `work_schedule_periods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_schedule_id` int(10) unsigned NOT NULL,
  `period_order` tinyint(3) unsigned NOT NULL,
  `period_start` time NOT NULL,
  `period_end` time NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schedule_period_order` (`work_schedule_id`,`period_order`),
  KEY `idx_schedule_period_time` (`work_schedule_id`,`period_start`,`period_end`),
  CONSTRAINT `fk_schedule_period_parent` FOREIGN KEY (`work_schedule_id`) REFERENCES `work_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=276 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `work_schedule_periods`
--

LOCK TABLES `work_schedule_periods` WRITE;
/*!40000 ALTER TABLE `work_schedule_periods` DISABLE KEYS */;
INSERT INTO `work_schedule_periods` VALUES (153,139,1,'09:00:00','14:00:00','2026-09-11 21:25:54','2026-09-11 21:25:54'),(154,141,1,'09:00:00','17:00:00','2026-09-11 21:25:54','2026-09-11 21:25:54'),(155,143,1,'09:00:00','14:00:00','2026-09-11 21:25:54','2026-09-11 21:25:54');
/*!40000 ALTER TABLE `work_schedule_periods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `work_schedules`
--

DROP TABLE IF EXISTS `work_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `work_schedules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) unsigned NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `schedule_type` enum('Work','Off') NOT NULL DEFAULT 'Work',
  `shift_start` time DEFAULT NULL,
  `shift_end` time DEFAULT NULL,
  `break_minutes` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_day` (`employee_id`,`day_of_week`),
  CONSTRAINT `fk_schedule_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=884 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `work_schedules`
--

LOCK TABLES `work_schedules` WRITE;
/*!40000 ALTER TABLE `work_schedules` DISABLE KEYS */;
INSERT INTO `work_schedules` VALUES (139,28,'Monday','Work','09:00:00','14:00:00',0),(140,28,'Tuesday','Off',NULL,NULL,0),(141,28,'Wednesday','Work','09:00:00','17:00:00',0),(142,28,'Thursday','Off',NULL,NULL,0),(143,28,'Friday','Work','09:00:00','14:00:00',0),(144,28,'Saturday','Off',NULL,NULL,0),(145,28,'Sunday','Off',NULL,NULL,0),(146,11,'Monday','Work','08:00:00','17:00:00',60),(147,11,'Tuesday','Work','08:00:00','17:00:00',60),(148,11,'Wednesday','Work','08:00:00','17:00:00',60),(149,11,'Thursday','Work','08:00:00','17:00:00',60),(150,11,'Friday','Work','08:00:00','17:00:00',60),(151,11,'Saturday','Work','08:00:00','17:00:00',60),(152,11,'Sunday','Off',NULL,NULL,0),(153,1,'Monday','Work','08:00:00','17:00:00',60),(154,1,'Tuesday','Work','08:00:00','17:00:00',60),(155,1,'Wednesday','Work','08:00:00','17:00:00',60),(156,1,'Thursday','Work','08:00:00','17:00:00',60),(157,1,'Friday','Work','08:00:00','17:00:00',60),(158,1,'Saturday','Work','08:00:00','17:00:00',60),(159,1,'Sunday','Off',NULL,NULL,0),(451,154,'Monday','Work','08:00:00','17:00:00',60),(452,154,'Tuesday','Work','08:00:00','17:00:00',60),(453,154,'Wednesday','Work','08:00:00','17:00:00',60),(454,154,'Thursday','Work','08:00:00','17:00:00',60),(455,154,'Friday','Work','08:00:00','17:00:00',60),(456,154,'Saturday','Off',NULL,NULL,0),(457,154,'Sunday','Off',NULL,NULL,0);
/*!40000 ALTER TABLE `work_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'ucchr_system_recovered'
--

--
-- Dumping routines for database 'ucchr_system_recovered'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-19 21:46:38
