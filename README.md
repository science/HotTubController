# Hot Tub Controller

A web-based controller system for custom hot tub automation and monitoring.

## Overview

This project is a modernization of a custom Android Tasker App system, porting it to web standards to create a more accessible and reusable hot tub control system. The goal is to provide a robust, web-based interface for hot tub automation that others can easily adapt and extend.

## Features (In Development)

- **Backend API**: Complete PHP backend with storage system and external API integration ✅
- **Heating Control**: Core heating APIs with cron management system (Phase 1) ✅
- **Web Interface**: React-based dashboard for monitoring and control (Phase 2) 🚧
- **Mobile App**: Native mobile interface for remote control (Phase 3) 📅
- **Advanced Features**: ML prediction, analytics, notifications (Phase 3) 📅

## Background

Originally built as a custom Android Tasker application, this project represents a transition to web-based technologies to improve maintainability, accessibility, and community adoption.

## Current Status & Next Steps

### ✅ **Backend Foundation Complete** 
The PHP backend is fully implemented with:
- External API integration (WirelessTag temperature monitoring, IFTTT equipment control)
- Complete storage system with model-persistence layer
- 346+ tests passing with comprehensive safety features
- Authentication, CORS proxy, and API infrastructure

### ✅ **Phase 1: Heating Control APIs Complete**
Core heating control functionality is now implemented with:
- **Cron Management System**: Dynamic cron job scheduling with secure API key authentication
- **Start Heating API**: Initiates heating cycles with equipment control and monitoring setup
- **Temperature Monitoring API**: Intelligent monitoring loops with precision control near target temps
- **Emergency Stop API**: Complete heating cycle cleanup with equipment safety sequences
- **Comprehensive Testing**: Full test coverage for cron operations and heating control logic

### 🚧 **Next: Web Interface Foundation (Phase 2)**
The immediate next development phase focuses on building the React-based web interface. See [`backend/ROADMAP.md`](./backend/ROADMAP.md) for detailed implementation plans.

### 📚 **Documentation**
- **Backend Setup**: See [`backend/README.md`](./backend/README.md) for installation and development
- **API Documentation**: Available in [`backend/docs/`](./backend/docs/) directory
- **Development Roadmap**: Detailed phases in [`backend/ROADMAP.md`](./backend/ROADMAP.md)

## License

Licensed under the Apache License, Version 2.0. See [LICENSE](LICENSE) for details.

## Author

Stephen Midgley