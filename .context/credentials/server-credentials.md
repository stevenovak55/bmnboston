# Server Credentials

## bmnboston.com (Primary Site)

| Setting | Value |
|---------|-------|
| Host | 35.236.219.140 |
| Port | 57105 |
| Username | stevenovakcom |
| Password | cFDIB2uPBj5LydX |
| SSH Command | `ssh stevenovakcom@35.236.219.140 -p 57105` |

### SCP Example
```bash
sshpass -p 'cFDIB2uPBj5LydX' scp -P 57105 local_file.php stevenovakcom@35.236.219.140:~/public/wp-content/plugins/PLUGIN/
```

### SSH Example
```bash
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 "command here"
```

---

## steve-novak.com (Secondary Site)

| Setting | Value |
|---------|-------|
| Host | 35.236.219.140 |
| Port | 50594 |
| Username | stevenovakrealestate |
| Password | nxGDPBDdpeuh2Io |
| SSH Command | `ssh stevenovakrealestate@35.236.219.140 -p 50594` |

### SCP Example
```bash
sshpass -p 'nxGDPBDdpeuh2Io' scp -P 50594 local_file.php stevenovakrealestate@35.236.219.140:~/public/wp-content/plugins/PLUGIN/
```

### SSH Example
```bash
sshpass -p 'nxGDPBDdpeuh2Io' ssh -p 50594 stevenovakrealestate@35.236.219.140 "command here"
```

---

## Quick Reference

| Site | User | Port | Password |
|------|------|------|----------|
| bmnboston.com | stevenovakcom | 57105 | cFDIB2uPBj5LydX |
| steve-novak.com | stevenovakrealestate | 50594 | nxGDPBDdpeuh2Io |
