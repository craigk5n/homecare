-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)
--
-- Host: localhost    Database: intranet
-- ------------------------------------------------------
-- Server version	8.0.36-0ubuntu0.22.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `booklog`
--

DROP TABLE IF EXISTS `booklog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booklog` (
  `bl_id` int NOT NULL DEFAULT '0',
  `bl_title` varchar(150) NOT NULL DEFAULT '',
  `bl_author` varchar(100) NOT NULL DEFAULT '',
  `bl_pub_year` int DEFAULT NULL,
  `bl_rating` int DEFAULT NULL,
  `bl_isbn` varchar(30) DEFAULT NULL,
  `bl_category` int DEFAULT NULL,
  `bl_comments` text,
  `bl_date` int NOT NULL DEFAULT '0',
  `bl_login` varchar(25) NOT NULL DEFAULT '',
  PRIMARY KEY (`bl_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booklog`
--
-- WHERE:  TABLE_NAME LIKE 'hc_%'

