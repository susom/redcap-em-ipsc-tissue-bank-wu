# IPSC Tissue Bank - Wu Lab
Custom EM to manage storage and distribution of tissue sample vial for Wu iPSC lab.  This EM includes and extends the 
functionality of Instance Table EM.  These two EMs should not be deployed in the project.

The EM assumes the presences of a "sample" instrument and "vial" instrument.  The "vial" instrument is displayed 
within the "sample" instrument in two separate tables, depending on the vial status.  

When a new sample is created, a bulk number of vial ids and freezer spots can be assigned.  The initial number of 
slots in the A, B or D freezer is entered in the sample form.  The freezer slots are assigned according to the following algorithm:
* If the number of slots for a freezer is less than 5, then the first freezer box with enough freezer slots is 
  selected, and freezer slots are filled from lowest to highest, but not necessarily in consecutive order.
  
* If the number of slots for a freezer is greater or equal to 5, then the first empty freezer box is selected.  If 
  the box prior to the first emply freezer box has enough *consecutive* spaces, then the prior box is select.  Slots 
  are filled consecutively, from lowest to highest.

After the sample has been created, new vials may only be added one by one in "frozen" state.  When new vials are added, 
the vial is assigned a random vial id and freezer space is assigned to the vial according to the next available slot in the designated freezer.

If the vial has status "frozen", then multiple bulk operations are possible -- distribute, print, move and delete
* Distribute - moves the vial status from "frozen" to "planned" or "shipped"
* Print - print the cryovial label for the vial
* Move - Assign a new freezer space for the vial
* Delete - Remove the vial from the database


If the vial has status "planned" or "distributed", then only two options are possible
* Cancel - move "planned" vials back to "frozen" status.  If the vial already has "shipped" status, then do nothing.
* Delete - Remove the vial from the database

The EM includes two custom reports
* The Planned Report - reports vials in "planned" status.  Vials can be bulk assigned to "shipped" status from this 
  report.
* The Empty Slot Report - reports available freezer slots
* The Moved Report - reports moved and previous location of vials

The EM relies on installation of the [Zebra Browser Driver](https://www.zebra.com/us/en/products/software/barcode-printers/link-os/browser-print.html) on the machine on which the EM is being 
used.  In addition, the machine must have the networked printer installed.  Unforunately, Zebra does not provide 
a Mac OS driver and the 3rd party driver does not work well.  

To connect the computer to the wireless Zebra printer:
1. Under Settings/Printers & Devices, select "The printer that I want isn't listed"
1. Select "Add a printer or network printer with manual settings"
1. Select "Create a new port", type "Standard TCP/IP Port"
1. Enter the printer IP address and give the printer a name
1. Additional port information required, select "Custom"
1. Select "Raw" protocol, enter 9100 for port number
1. Select ZDesigner, ZDesigner ZD420 203dpi ZPL (or whatever the printer model is.  Make sure to select "ZPL" and 
   the correct dpi)
   
To configure the Zebra Browser Driver:
1. Select the wireless zebra printer as the default printer
1. When printing for the first time, the browser printer driver will ask for access.
1. When active the Zebra Browser Driver will have a small zebra icon in the Windows Application Tray.

